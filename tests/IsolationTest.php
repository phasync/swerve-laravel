<?php

/*
 * What a request does to Laravel's state must stay with that request: not seen by the requests that run
 * meanwhile (they overlap whenever one waits), nor by the ones that follow on the same pooled application.
 * The routes are tests/Fixtures/routes/isolation.php. Each probe runs against a worker of its own,
 * so that one leak cannot colour another. What a request can still change for the others is in
 * docs/shared-state.md.
 */

/**
 * Run the requests [[Browser, path, delay seconds, method, body, headers]], each started $delay
 * seconds after the first; the decoded JSON bodies in order, with the HTTP status.
 */
function iso_overlap(array $requests): array
{
    $multi   = \curl_multi_init();
    $handles = $responses = [];
    $start   = \microtime(true);
    $pending = $requests;
    do {
        foreach ($pending as $i => $r) {
            [$browser, $path, $delay] = $r;
            if (\microtime(true) - $start >= $delay) {
                $handles[$i] = \curl_init("http://{$browser->addr}$path");
                \curl_setopt_array($handles[$i], $browser->options($r[3] ?? 'GET', [...($r[5] ?? []), 'Accept: application/json'], $r[4] ?? null, $responses[$i]));
                \curl_multi_add_handle($multi, $handles[$i]);
                unset($pending[$i]);
            }
        }
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.02);
    } while ($running > 0 || $pending);
    $out = [];
    foreach ($handles as $i => $ch) {
        $body    = \curl_multi_getcontent($ch);
        $out[$i] = (\json_decode($body, true) ?? ['body' => $body]) + ['status' => \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE)];
        \curl_multi_remove_handle($multi, $ch);
    }
    \ksort($out);

    return $out;
}

/** A's window contains B's: B started and ended while A was waiting. */
function iso_nested(array $a, array $b): void
{
    expect($a['start'] < $b['start'] && $b['end'] < $a['end'])->toBeTrue('requests did not overlap: ' . \json_encode([$a['start'], $a['end'], $b['start'], $b['end']]));
}

beforeEach(function () {
    $this->iso = fn (Closure $test) => with_app($test, workers: 1);
});

/** Per probe: login (browsers log in as their tag), own (reads its own identity), after(tag, base), base(tag), distinct, regen, kept. */
function iso_cases(): array
{
    $ab    = fn ($a, $b) => fn ($t) => 'A' === $t ? $a : $b;
    $list  = fn ($t) => [$t];
    $same  = fn ($t, $base) => $base;
    $cases = [];
    foreach (['config-key', 'container-instance', 'container-singleton', 'container-resolving', 'container-extend', 'container-contextual'] as $p) {
        $cases[$p] = [];
    }
    foreach (['container-alias', 'container-tag', 'container-provider'] as $p) {
        $cases[$p] = ['after' => $list];
    }
    $cases['cookie-queue']        = ['after' => fn ($t) => ["iso_$t"]];
    $cases['auth-shoulduse']      = ['after' => fn ($t) => "iso-$t"];
    $cases['locale']              = ['after' => $ab('nb', 'de')];
    $cases['fallback-locale']     = ['after' => $ab('nb', 'de')];
    $cases['carbon-locale']       = ['after' => $ab('nb', 'de')];
    $cases['config-timezone']     = ['after' => $ab('Europe/Oslo', 'Asia/Tokyo')];
    $cases['url-root']            = ['after' => fn ($t) => 'http://' . \strtolower($t) . '.example/x'];
    $cases['url-scheme']          = ['after' => $ab('https', 'http')];
    $cases['url-defaults']        = ['after' => fn ($t) => ['iso' => $t]];
    $cases['url-setrequest']      = ['after' => fn ($t) => \strtolower($t) . '.example'];
    $cases['url-previous']        = ['after' => fn ($t) => "http://prev-$t.test/"];
    $cases['url-intended']        = ['after' => fn ($t) => "http://int-$t.test/"];
    $cases['route-param']         = ['own' => true];
    $cases['auth-user']           = ['own' => true, 'login' => true];
    $cases['auth-setuser']        = ['login' => true, 'base' => fn ($t) => $t];
    $cases['auth-guard']          = ['login' => true, 'base' => fn ($t) => $t];
    $cases['session-id']          = ['distinct' => true, 'after' => $same];
    $cases['csrf-token']          = ['distinct' => true, 'after' => $same];
    $cases['session-regenerate']  = ['distinct' => true, 'regen' => true];
    $cases['session-put']         = ['kept' => true];
    $cases['session-flash']       = ['kept' => true];
    $cases['session-old']         = ['kept' => true];

    return \array_map(fn ($o) => $o + ['after' => fn ($t) => $t], $cases);
}

// Request A sets the state and waits; request B, another browser and session, starts meanwhile, sets
// its own and finishes first; the requests C, D and E come after both
foreach (iso_cases() as $probe => $opt) {
    test("request state '$probe' stays with its own request", function () use ($probe, $opt) {
        ($this->iso)(function (string $addr) use ($probe, $opt) {
            $login = $opt['login'] ?? false;
            $make  = function (string $tag) use ($addr, $login) {
                $b = new Browser($addr);
                $login && $b->get("/login-as/$tag");

                return $b;
            };
            $first = fn (Browser $b, string $tag, string $query) => $b->json("/iso/$probe/$tag$query");
            $own   = $opt['own'] ?? false;
            $base0 = $first($make('Z'), 'Z', '?wait=0')['before'];
            $base  = fn (string $t) => $own ? $t : (isset($opt['base']) ? $opt['base']($t) : $base0);
            $after = fn (string $t) => $own ? $t : $opt['after']($t, $base($t));

            [$ba, $bb] = [$make('A'), $make('B')];
            [$a, $b]   = iso_overlap([[$ba, "/iso/$probe/A?mode=set&wait=0.8", 0], [$bb, "/iso/$probe/B?mode=set&wait=0.1", 0.3]]);
            expect($a['status'] . $b['status'])->toBe('200200', \substr(\json_encode([$a, $b]), 0, 600));
            iso_nested($a, $b);
            if ($opt['distinct'] ?? false) {
                $c = $first($make('C'), 'C', '?wait=0');
                expect(\count(\array_unique([$a['before'], $b['before'], $c['before']])))->toBe(3, 'per-request values shared: ' . \json_encode([$a, $b, $c]));
                if ($opt['regen'] ?? false) {
                    expect($a['after'])->not->toBe($a['before'])->and($b['before'])->not->toBe($a['after']);
                }

                return;
            }
            // Every deviation is collected, so a failure says which of the overlapping and the later requests saw what
            $problems = [];
            $check    = function (string $what, mixed $got, mixed $want) use (&$problems) {
                $got === $want || $problems[] = $what . ': saw ' . \json_encode($got) . ', wanted ' . \json_encode($want);
            };
            $check('concurrent: B before setting its own', $b['before'], $base('B'));
            $check('concurrent: B after setting', $b['after'], $after('B'));
            $check('concurrent: A after waiting (B set its own meanwhile)', $a['after'], $after('A'));
            $check('concurrent: A before', $a['before'], $base('A'));
            foreach (['C', 'D', 'E'] as $tag) {
                $check("sequential: $tag after A and B", $first($make($tag), $tag, '?wait=0')['before'], $base($tag));
            }
            // What a session kept is its own
            if ($opt['kept'] ?? false) {
                $check("kept: A's session", $first($ba, 'A', '?wait=0')['before'], $after('A'));
                $check("kept: B's session", $first($bb, 'B', '?wait=0')['before'], $after('B'));
            }
            expect($problems)->toBe([]);
        });
    });
}

/** [browser, path, headers] each, started at once; the responses decoded. */
function iso_together(array $requests): array
{
    $multi   = \curl_multi_init();
    $handles = $responses = [];
    foreach ($requests as $i => [$browser, $path, $headers]) {
        $handles[$i] = \curl_init("http://{$browser->addr}$path");
        \curl_setopt_array($handles[$i], $browser->options('GET', [...$headers, 'Accept: application/json'], null, $responses[$i]));
        \curl_multi_add_handle($multi, $handles[$i]);
    }
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.05);
    } while ($running > 0);
    foreach ($handles as $i => $ch) {
        $body = \curl_multi_getcontent($ch);
        $out  = \json_decode($body, true);
        if (!\is_array($out) || 200 !== \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE)) {
            throw new RuntimeException('Request ' . ($i + 1) . ' failed with ' . \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE) . ': ' . \substr($body, 0, 400));
        }
        $responses[$i] = $out;
    }

    return $responses;
}

// Request 1 sets the state and waits; request 2 starts meanwhile and must not see request 1's state, nor
// request 1 the state request 2 sets; request 3, after both, must not see what they left
foreach ([
    'Facade::$resolvedInstance, Event::fake()'               => 'facade-event-fake',
    'Facade::$resolvedInstance, Mail::fake()'                => 'facade-mail-fake',
    'Facade::$resolvedInstance, Queue::fake()'               => 'facade-queue-fake',
    'AbstractPaginator::$currentPageResolver'                => 'paginator-page',
    'AbstractPaginator::$currentPathResolver'                => 'paginator-path',
    'AbstractPaginator::$queryStringResolver'                => 'paginator-query',
    'AbstractPaginator::$viewFactoryResolver'                => 'paginator-views',
    'Component::$factory'                                    => 'component-factory',
    'Uri::$urlGeneratorResolver'                             => 'uri-resolver',
    'Gate user resolver'                                     => 'gate-user',
    'MailManager mailer, Mail::alwaysTo()'                   => 'mail-always-to',
    'LogManager shared context, Log::shareContext()'         => 'log-shared-context',
    'CarbonInterval locale after App::setLocale()'           => 'carbon-interval-locale',
    'Carbon diffForHumans() locale after App::setLocale()'   => 'carbon-diff-locale',
] as $label => $probe) {
    test("static state: $label", function () use ($probe) {
        $problems = [];
        $log      = with_app(function (string $addr) use ($probe, &$problems) {
            // Three applications, so none is booted while the probe runs
            iso_together(\array_map(fn () => [new Browser($addr), '/iso/route-param/W?wait=0.3', []], [1, 2, 3]));
            [$one, $two] = iso_together([
                [new Browser($addr), "/iso-static/$probe/1?page=1&wait=0.8", ['Host: h1.test']],
                [new Browser($addr), "/iso-static/$probe/2?page=2&wait=0.1&delay=0.25", ['Host: h2.test']],
            ]);
            [$three] = iso_together([[new Browser($addr), "/iso-static/$probe/3?page=3", ['Host: h3.test']]]);

            if (!($two['start'] > $one['set'] && $two['end'] < $one['read'])) {
                throw new RuntimeException('The requests did not overlap: ' . \json_encode([$one, $two]));
            }
            $ambient = 'ambient' === $one['kind'];
            $mine    = fn (string $tag) => 'flag' === $one['kind'] ? 'set' : $tag;
            $none    = fn (string $tag) => $ambient ? $tag : 'none';
            $want    = [
                'request 2 before (while request 1 holds its state)'  => [$two['before'], $none('2')],
                'request 1 after (request 2 set its own meanwhile)'   => [$one['after'], $mine('1')],
                'request 2 after'                                     => [$two['after'], $mine('2')],
                'request 3 before (after requests 1 and 2 were done)' => [$three['before'], $none('3')],
            ];
            foreach ($want as $what => [$got, $expected]) {
                $expected === $got || $problems[] = "$what: saw '$got', wanted '$expected'";
            }
        }, 1); // one worker, or the requests may not share statics
        expect($problems)->toBe([]);
        expect($log)->not->toMatch('/error|exception/i');
    });
}

function iso_mariadb_up(): bool
{
    $c = @\fsockopen(\getenv('ISO_MYSQL_HOST') ?: '127.0.0.1', (int) (\getenv('ISO_MYSQL_PORT') ?: 13306), $e, $s, 0.5);

    return $c && \fclose($c);
}

test('concurrent requests do not share a database connection, transaction, session variable, temp table or query log', function () {
    with_app(function (string $addr) {
        [$a, $b] = \array_map(fn ($r) => \json_decode($r['body'], true), overlapping([[new Browser($addr), '/iso-db/A'], [new Browser($addr), '/iso-db/B']]));
        expect($a['start'] < $b['end'] && $b['start'] < $a['end'])->toBeTrue('the requests did not overlap');
        expect([$a['conn'] !== $b['conn'], $a['level'], $b['level'], $a['var'], $b['var'], $a['tmp'], $b['tmp'], $a['log'], $b['log']])
            ->toBe([true, 1, 1, 'A', 'B', ['A'], ['B'], 3, 3]);
    }, workers: 1);
})->skip(!iso_mariadb_up() || !ext_loaded(), 'needs the MariaDB test container and phasync-ext, for queries that wait without blocking the worker');

test('terminable middleware runs once per request, with that request', function () {
    $log = APP . '/storage/iso-term.log';
    @\unlink($log);
    with_app(function (string $addr) use ($log) {
        [$a, $b] = iso_overlap([[new Browser($addr), '/iso-term/A?wait=0.8', 0], [new Browser($addr), '/iso-term/B?wait=0.1', 0.3]]);
        iso_nested($a, $b);
        foreach (['C', 'D'] as $t) {
            (new Browser($addr))->get("/iso-term/$t?wait=0");
        }
        \usleep(300000);
        $lines = \array_map(fn ($l) => \json_decode($l, true), \file($log, \FILE_IGNORE_NEW_LINES));
        \usort($lines, fn ($x, $y) => $x['url'] <=> $y['url']);
        expect($lines)->toBe(\array_map(fn ($t) => ['url' => $t, 'header' => $t, 'current' => $t], ['A', 'B', 'C', 'D']));
    }, workers: 1);
});

test('route-model binding that waits keeps each request its own parameter', function () {
    with_app(function (string $addr) {
        [$a, $b] = iso_overlap([[new Browser($addr), '/iso-bind/A', 0], [new Browser($addr), '/iso-bind/B', 0.3]]);
        // B's window falls inside A's resolving of the binding
        expect($b['start'] < $a['start'] + 0.8 + 0.3)->toBeTrue();
        expect([$a['param'], $a['route'], $a['current'], $a['path']])->toBe(['A', 'A', 'A', 'iso-bind/A']);
        expect([$b['param'], $b['route'], $b['current'], $b['path']])->toBe(['B', 'B', 'B', 'iso-bind/B']);
        $c = (new Browser($addr))->json('/iso-bind/C');
        expect([$c['param'], $c['route'], $c['current']])->toBe(['C', 'C', 'C']);
    }, workers: 1);
});

test('validation failures that wait keep each request its own errors', function () {
    with_app(function (string $addr) {
        [$a, $b] = iso_overlap([[new Browser($addr), '/iso-validate/A', 0], [new Browser($addr), '/iso-validate/B', 0.3]]);
        iso_nested($a, $b);
        expect([$a['message'], $a['bag']])->toBe(['bad-A', ['bad-A']]);
        expect([$b['message'], $b['bag']])->toBe(['bad-B', ['bad-B']]);
        $c = (new Browser($addr))->json('/iso-validate/C');
        expect($c['bag'])->toBe(['bad-C']);
    }, workers: 1);
});

test('cookies and headers put on a response reach only that response', function () {
    with_app(function (string $addr) {
        $ba      = new Browser($addr);
        $bb      = new Browser($addr);
        [$a, $b] = iso_overlap([[$ba, '/iso-cookie/A?wait=0.8', 0], [$bb, '/iso-cookie/B?wait=0.1', 0.3]]);
        iso_nested($a, $b);
        $names = fn (Browser $br) => \array_values(\array_filter(\array_keys($br->cookies), fn ($n) => \str_starts_with($n, 'iso_')));
        expect($names($ba))->toBe(['iso_A'])->and($names($bb))->toBe(['iso_B']);
        $c = new Browser($addr);
        $r = $c->get('/iso-cookie/C?wait=0');
        expect($names($c))->toBe(['iso_C'])->and(\array_keys($r['headers']))->not->toContain('x-iso-a', 'x-iso-b');
    }, workers: 1);
});

test('a login in one request is not another request\'s user', function () {
    with_app(function (string $addr) {
        $anon = new Browser($addr);
        $bob  = new Browser($addr);
        $bob->get('/login-as/Bob');
        $ann = new Browser($addr);
        // Ann logs in while Bob's slow request is in flight; the anonymous request starts after her login
        [$slow, $login, $guest] = iso_overlap([
            [$bob, '/iso-who?wait=1', 0],
            [$ann, '/login-as/Ann', 0.2],
            [$anon, '/iso-who?wait=0.1', 0.5],
        ]);
        expect($slow['before'])->toBe([true, 'Bob', 'Bob'])->and($slow['after'])->toBe([true, 'Bob', 'Bob', 'Bob']);
        expect($guest['before'])->toBe([false, null, null])->and($guest['after'])->toBe([false, null, null, null]);
        expect($ann->json('/iso-who?wait=0')['before'])->toBe([true, 'Ann', 'Ann']);
        expect((new Browser($addr))->json('/iso-who?wait=0')['before'])->toBe([false, null, null]);
    }, workers: 1);
});
