<?php

/*
 * Adversarial: resources and lifetime that requests share by accident, concurrently or one
 * after the other in a worker. Routes: tests/Fixtures/routes/audit-resources.php. Deliberate
 * sharing (cache contents, rows, files, boot-time config) is not probed. MySQL tests need the
 * MariaDB test container on 127.0.0.1:13306 (ADV_MYSQL_HOST / ADV_MYSQL_PORT).
 */

function audx_mysql_up(): bool
{
    $c = @\fsockopen(\getenv('ADV_MYSQL_HOST') ?: '127.0.0.1', (int) (\getenv('ADV_MYSQL_PORT') ?: 13306), $e, $s, 0.5);

    return $c && \fclose($c);
}

/** The decoded bodies of requests made at once. */
function audx_together(string $addr, array $paths): array
{
    return \array_map(fn ($r) => \json_decode($r['body'], true) ?? $r['body'], overlapping(\array_map(fn ($p) => [new Browser($addr), $p], $paths)));
}

/** One request after another, each on a connection of its own; the decoded bodies. */
function audx_seq(string $addr, array $paths): array
{
    $b = new Browser($addr);

    return \array_map(function ($p) use ($b) {
        $body = $b->get($p)['body'];

        return \json_decode($body, true) ?? $body;
    }, $paths);
}

/** Whether two [start, end] spans overlap in time. */
function audx_overlap(array $a, array $b): bool
{
    return $a['start'] < $b['end'] && $b['start'] < $a['end'];
}

// ---- (a) database -------------------------------------------------------------------------

// Probes: connection id, transaction, @session variable, temp table and query log of two requests whose queries really overlap (SELECT SLEEP)
test('concurrent requests do not share a database connection, transaction, session variable, temp table or query log', function () {
    with_app(function (string $addr) {
        [$a, $b] = audx_together($addr, ['/audx/db/A', '/audx/db/B']);
        expect(audx_overlap($a, $b))->toBeTrue('the requests did not overlap');
        expect([$a['conn'] !== $b['conn'], $a['level'], $b['level'], $a['var'], $b['var'], $a['tmp'], $b['tmp'], $a['log'], $b['log']])
            ->toBe([true, 1, 1, 'A', 'B', ['A'], ['B'], 3, 3]);
    }, workers: 1);
})->skip(!audx_mysql_up(), 'MariaDB test container not reachable');

// Probes: what a connection keeps after the request that used it (session variable, temp table, query log), seen by the next request
test('a request does not see the session variable, temp table or query log of the request before it', function () {
    with_app(function (string $addr) {
        audx_seq($addr, ['/audx/db/A']);
        [$state] = audx_seq($addr, ['/audx/db-state']);
        expect($state)->toBe(['level' => 0, 'var' => null, 'tmp' => 0, 'logging' => false, 'log' => 0]);
    }, workers: 1);
})->skip(!audx_mysql_up(), 'MariaDB test container not reachable');

// Probes: a transaction left open (returned from, or abandoned by an exception) is rolled back with the request
test('a transaction a request leaves open does not reach the next request', function (string $how) {
    with_app(function (string $addr) use ($how) {
        audx_seq($addr, ["/audx/db-leave-open/$how"]);
        expect(audx_seq($addr, ["/audx/db-open-exists/$how"])[0])->toBe(['level' => 0, 'exists' => false]);
    }, workers: 1);
})->with(['returned', 'throw']);

// Probes: DB::listen of one request firing for the queries of the next, and of one running meanwhile
test('DB::listen of a request fires for that request only', function (string $mode) {
    with_app(function (string $addr) use ($mode) {
        if ('later' === $mode) {
            audx_seq($addr, ['/audx/db-listen/A', '/audx/db-query', '/audx/db-query']);
        } else {
            audx_together($addr, ['/audx/db-listen/A?wait=0.3', '/audx/db-query']);
        }
        expect(audx_seq($addr, ['/audx/log'])[0])->toBe([]);
    }, workers: 1);
})->with(['later', 'meanwhile']);

// Probes: DB::enableQueryLog() of one request, seen by the next and by a request running meanwhile
test('the query log of a request is not on for other requests', function (string $mode) {
    with_app(function (string $addr) use ($mode) {
        if ('later' === $mode) {
            audx_seq($addr, ['/audx/db-querylog/on']);
            $state = audx_seq($addr, ['/audx/db-querylog/state'])[0];
        } else {
            $state = audx_together($addr, ['/audx/db-querylog/on?wait=0.3', '/audx/db-querylog/state'])[1];
        }
        expect($state)->toBe(['logging' => false, 'log' => 0]);
    }, workers: 1);
})->with(['later', 'meanwhile']);

// Probes: Eloquent's static switches (unguard, lazy-loading and discarded-attribute prevention), set by one request
test('Model::unguard() and Model::prevent*() of a request do not outlive it', function (string $set) {
    with_app(function (string $addr) use ($set) {
        audx_seq($addr, ["/audx/model/$set"]);
        expect(audx_seq($addr, ['/audx/model/state'])[0])->toBe(['unguarded' => false, 'lazy' => false, 'discard' => false]);
    }, workers: 1);
})->with(['unguard', 'strict']);

// Probes: a model event listener (User::creating()) added by a request, firing in the next request's create
test('a model event listener of a request does not hear other requests', function () {
    with_app(function (string $addr) {
        audx_seq($addr, ['/audx/model/listen/A', '/audx/model/create/B']);
        expect(audx_seq($addr, ['/audx/log'])[0])->toBe([]);
    }, workers: 1);
});

// ---- (b) events, fakes, mail, schedule ----------------------------------------------------

// Probes: Event::listen of one request, heard by the next and by a request running meanwhile
test('an event listener of a request is not heard by other requests', function (string $mode) {
    with_app(function (string $addr) use ($mode) {
        if ('later' === $mode) {
            audx_seq($addr, ['/audx/event/listen/A', '/audx/event/dispatch']);
        } else {
            audx_together($addr, ['/audx/event/listen/A?wait=0.3', '/audx/event/dispatch']);
        }
        expect(audx_seq($addr, ['/audx/log'])[0])->toBe([]);
    }, workers: 1);
})->with(['later', 'meanwhile']);

// Probes: Event/Queue/Mail/Bus::fake() in one request, seen by a request running meanwhile that uses the real thing
test('a fake in one request does not replace the real service in a concurrent request', function (string $what) {
    with_app(function (string $addr) use ($what) {
        [$fake, $real] = audx_together($addr, ["/audx/fake/$what", "/audx/real/$what"]);
        expect(audx_overlap($fake, $real))->toBeTrue('the requests did not overlap');
        expect($real['faked'])->toBeFalse("$what is faked in the other request")
            ->and($real['out'])->toBeIn([1, true]);
    }, workers: 1);
})->with(['Event', 'Queue', 'Mail', 'Bus']);

// Probes: Event::fake() replacing Eloquent's event dispatcher for good: model events of later requests reach real listeners
test('model events still reach listeners after a request used Event::fake()', function () {
    with_app(function (string $addr) {
        audx_seq($addr, ['/audx/fake/Event', '/audx/model/observe-events', '/audx/model/create/B']);
        expect(audx_seq($addr, ['/audx/log'])[0])->toBe(['created-heard']);
    }, workers: 1);
});

// Probes: mail kept by the array transport, and events added to the scheduler, by earlier requests
test('mail sent and tasks scheduled by a request are not there for the next', function () {
    with_app(function (string $addr) {
        audx_seq($addr, ['/audx/mail/send', '/audx/schedule/add']);
        expect(audx_seq($addr, ['/audx/mail/count', '/audx/schedule/count']))->toBe([0, 0]);
    }, workers: 1);
});

/** The log lines the routes wrote, polled until $n arrived; then once more, for lines that came twice. */
function audx_log(string $addr, int $n): array
{
    $b     = new Browser($addr);
    $lines = [];
    $until = \microtime(true) + 3;
    while (\count($lines) < $n && \microtime(true) < $until) {
        $lines = \array_merge($lines, \json_decode($b->get('/audx/log')['body'], true));
        \usleep(50_000);
    }
    \usleep(300_000);
    $lines = \array_merge($lines, \json_decode($b->get('/audx/log')['body'], true));
    \sort($lines);

    return $lines;
}

function audx_vitals(string $addr): array
{
    return (new Browser($addr))->json('/audx/vitals?gc=1');
}

/** Send a request and close the connection after $seconds, as a client that leaves. */
function audx_abandon(string $addr, string $path, float $seconds = 0.3)
{
    $conn = \stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    \fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\n\r\n");
    \usleep((int) ($seconds * 1e6));

    return $conn;
}

// ---- (b) terminating, deferred, after-response callbacks ---------------------------------

// Probes: app()->terminating(), defer() and dispatch()->afterResponse() of a request run once, in that request's application
test('terminating, deferred and after-response callbacks run once, in their own request\'s application', function (string $kind, string $mode) {
    with_app(function (string $addr) use ($kind, $mode) {
        if ('concurrent' === $mode) {
            audx_together($addr, ["/audx/$kind/A", "/audx/$kind/B"]);
        } else {
            audx_seq($addr, ["/audx/$kind/A", "/audx/$kind/B"]);
        }
        expect(audx_log($addr, 2))->toBe(["$kind-A:A", "$kind-B:B"]);
    }, workers: 1);
})->with(['term', 'defer', 'after'])->with(['concurrent', 'sequential']);

// Probes: App::booted() on a booted application runs the callback now, once, and not again for later requests
test('app()->booted() callbacks of a request run once', function () {
    with_app(function (string $addr) {
        audx_seq($addr, ['/audx/booted/A', '/audx/booted/B', '/audx/ping/C']);
        expect(audx_log($addr, 2))->toBe(['booted-A', 'booted-B']);
    }, workers: 1);
});

// Probes: register_shutdown_function() inside a request, which PHP runs at the end of the worker, not of the request
test('a shutdown function registered by a request has run when the request is over', function () {
    with_app(function (string $addr) {
        audx_seq($addr, ['/audx/shutdown/A']);
        expect((new Browser($addr))->get('/audx/shutdown-seen')['body'])->toBe('A');
    }, workers: 1);
});

// ---- (c) lifetime ---------------------------------------------------------------------------

// Probes: the application, request, session store and scoped instance of finished requests are collectable (one per application may stay: the router's last request)
test('finished requests\' applications, requests and session stores are freed', function (string $mode) {
    with_app(function (string $addr) use ($mode) {
        for ($i = 0; $i < 24; $i += 'concurrent' === $mode ? 4 : 1) {
            'concurrent' === $mode
                ? overlapping(\array_map(fn ($j) => [new Browser($addr), '/audx-web/track/t' . ($i + $j)], [0, 1, 2, 3]))
                : (new Browser($addr))->get("/audx-web/track/t$i");
        }
        $v = audx_vitals($addr);
        expect($v['tracked'])->toBe(24);
        $max = 'concurrent' === $mode ? 8 : 1;
        expect(\max($v['alive']))->toBeLessThanOrEqual($max, 'still alive after gc: ' . \json_encode($v['alive']));
    }, workers: 1);
})->with(['sequential', 'concurrent']);

// Probes: memory, file descriptors, temp files, output buffers, error/exception handler stacks and autoloaders after many requests (some failing, some uploading)
test('a worker does not grow over hundreds of requests', function (string $mode) {
    with_app(function (string $addr) use ($mode) {
        $upload = \tempnam(\sys_get_temp_dir(), 'adv');
        \file_put_contents($upload, 'hello');
        $round = function (int $n) use ($addr, $mode, $upload) {
            for ($i = 0; $i < $n; $i += 'concurrent' === $mode ? 6 : 1) {
                $paths = ['/audx-web/work', '/audx/boom/' . ['throw', 'abort', 'error', 'warning'][$i % 4]];
                if ('concurrent' === $mode) {
                    overlapping(\array_map(fn ($p) => [new Browser($addr), $p], \array_merge($paths, $paths, $paths)));
                } else {
                    foreach ($paths as $p) {
                        (new Browser($addr))->get($p);
                    }
                }
            }
            $b = new Browser($addr);
            for ($i = 0; $i < 5; ++$i) {
                $b->request('POST', '/upload', [], ['doc' => new CURLFile($upload), 'other' => new CURLFile($upload)]);
            }
        };
        $round(90);
        $before = audx_vitals($addr);
        $round('concurrent' === $mode ? 360 : 120);
        $after = audx_vitals($addr);
        \unlink($upload);
        $growth = $after['memory'] - $before['memory'];
        $keep   = fn ($v) => \array_diff_key($v, ['memory' => 1, 'tracked' => 1, 'alive' => 1]);
        expect($keep($after))->toBe($keep($before));
        expect($growth)->toBeLessThan(600_000, "memory grew by $growth bytes");
    }, workers: 1);
})->with(['sequential', 'concurrent']);

// Probes: the temp files of an upload the route did not move; PHP-FPM removes them with the request
test('the temp file of an uploaded file the route left in place is removed after the request', function () {
    with_app(function (string $addr) {
        $upload = \tempnam(\sys_get_temp_dir(), 'adv');
        \file_put_contents($upload, 'hello');
        $sent = (new Browser($addr))->request('POST', '/upload', [], ['doc' => new CURLFile($upload), 'other' => new CURLFile($upload)]);
        \unlink($upload);
        $tmp = \json_decode($sent['body'], true)['tmp'];
        expect(\array_values(\array_filter($tmp, 'file_exists')))->toBe([]);
    }, workers: 1);
});

// ---- exceptions in one request ----------------------------------------------------------------

// Probes: an exception, abort() or PHP error in A (with an output buffer open and state set) must not harm B running meanwhile or the requests after
test('a request that fails leaves no state behind and does not harm a concurrent request', function (string $kind) {
    with_app(function (string $addr) use ($kind) {
        $base          = (new Browser($addr))->json('/audx/tagstate');
        [$boom, $slow] = overlapping([[new Browser($addr), "/audx/boom/$kind"], [new Browser($addr), '/audx/slow/B']]);
        $slow          = \json_decode($slow['body'], true);
        expect($slow['tag'] ?? null)->toBe('B');
        expect((new Browser($addr))->json('/audx/tagstate'))->toBe($base);
        expect((new Browser($addr))->json('/audx/ping/C')['tag'])->toBe('C');
    }, workers: 1);
})->with(['throw', 'abort', 'error', 'warning']);

// Probes: exit() or a fatal error in A ends the process; B waiting in the same worker meanwhile
test('exit() or a fatal error in one request does not take a concurrent request with it', function (string $kind) {
    with_app(function (string $addr) use ($kind) {
        [, $slow] = overlapping([[new Browser($addr), "/audx/boom/$kind"], [new Browser($addr), '/audx/slow/B']]);
        expect(\json_decode($slow['body'], true)['tag'] ?? $slow['body'])->toBe('B');
    }, workers: 1);
})->with(['exit', 'fatal']);

// Probes: error_get_last(), which PHP keeps for the whole process, read by a request after another request's suppressed warning
test('error_get_last() shows a request only its own errors', function () {
    with_app(function (string $addr) {
        [, $last] = audx_together($addr, ['/audx/suppressed', '/audx/last-error']);
        expect($last)->toBe(['last' => null]);
    }, workers: 1);
});

// ---- a request the client leaves, long responses ----------------------------------------------------

// Probes: a client leaving while its request holds an open transaction; the transaction and the application must be released
test('a request the client abandons mid-transaction releases its transaction and application', function () {
    with_app(function (string $addr) {
        $conn = audx_abandon($addr, '/audx/hold');
        \fclose($conn);
        $state = eventually(fn () => (new Browser($addr))->json('/audx/db-open-exists/hold'), ['level' => 0, 'exists' => false], 6);
        expect($state)->toBe(['level' => 0, 'exists' => false]);
        expect(\max(audx_vitals($addr)['alive']))->toBeLessThanOrEqual(1, 'the application of the request is still alive');
    }, workers: 1);
});

// Probes: a Cache::lock() held by a request the client abandons is released
test('a lock held by a request the client abandons is released', function () {
    with_app(function (string $addr) {
        \fclose(audx_abandon($addr, '/audx/lock'));
        expect(eventually(fn () => (new Browser($addr))->json('/audx/lock-free'), ['free' => true], 6))->toBe(['free' => true]);
    }, workers: 1);
});

// Probes: streamed responses of overlapping requests: the application the stream's callback runs in, after the route returned
test('the callback of a streamed response runs in its own request\'s application', function () {
    with_app(function (string $addr) {
        $tags   = ['s0', 's1', 's2', 's3'];
        $bodies = \array_column(overlapping(\array_map(fn ($t) => [new Browser($addr), "/audx/stream/$t?q=$t"], $tags)), 'body');
        $seen   = \array_map(fn ($b) => \array_map(fn ($l) => \array_slice(\json_decode($l, true), 0, 4), \explode("\n", \trim($b))), $bodies);
        expect($seen)->toBe(\array_map(fn ($t) => \array_fill(0, 3, [$t, $t, $t, $t]), $tags));
    }, workers: 1);
});

// Probes: a never-ending stream in A while other requests are served; then A's client leaves (its stream must end and its application be freed)
test('a long stream does not hold up other requests, and ends and is freed when its client leaves', function () {
    with_app(function (string $addr) {
        $conn = audx_abandon($addr, '/audx/forever', 0.3);
        $slow = [];
        for ($i = 0; $i < 6; ++$i) {
            $r = (new Browser($addr))->get("/audx/ping/p$i");
            expect(\json_decode($r['body'], true)['tag'] ?? null)->toBe("p$i");
            $slow[] = $r['time'];
        }
        expect(\max($slow))->toBeLessThan(0.5, 'a request waited for the stream');
        \fclose($conn);
        expect(eventually(fn () => (new Browser($addr))->get('/audx/forever-ended')['body'], 'yes', 6))->toBe('yes');
        (new Browser($addr))->get('/audx/ping/q');
        expect(\max(audx_vitals($addr)['alive']))->toBeLessThanOrEqual(1, 'the application of the request is still alive');
    }, workers: 1);
});

// Probes: the worker after exit() or a fatal error in a request: it must serve the next request
test('the worker serves again after exit() or a fatal error in a request', function (string $kind) {
    with_app(function (string $addr) use ($kind) {
        (new Browser($addr))->get("/audx/boom/$kind");
        $tag = eventually(fn () => (new Browser($addr))->json('/audx/ping/C')['tag'] ?? null, 'C', 8);
        expect($tag)->toBe('C');
    }, workers: 1);
})->with(['exit', 'fatal']);
