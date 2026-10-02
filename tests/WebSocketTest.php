<?php

/*
 * WebSockets from Laravel routes: Route::get() returning WebSocket::from(). The fixture's
 * callbacks each keep a file in storage/ws-live while they run, counted by GET /ws/live.
 */

beforeEach(function () {
    \array_map('unlink', \glob(APP . '/storage/ws-live/*'));
});

/** Open $n connections to $path, each after the server said its subscription is there; returns them and the workers' pids. */
function ws_subscribers(string $addr, int $n): array
{
    $conns = $pids = [];
    for ($i = 0; $i < $n; ++$i) {
        $conns[$i]        = ws_connect($addr, '/ws/news');
        [$opcode, $hello] = ws_read($conns[$i]);
        expect($opcode)->toBe(1)->and($hello)->toStartWith('subscribed ');
        $pids[\substr($hello, 11)] = true;
    }

    return [$conns, \array_keys($pids)];
}

test('both ways: text and binary echoed, many messages in a row, from a route', function () {
    $log = with_app(function (string $addr) {
        $conn = ws_connect($addr, '/ws');
        for ($i = 0; $i < 50; ++$i) {
            ws_send($conn, "message $i");
        }
        for ($i = 0; $i < 50; ++$i) {
            expect(ws_read($conn))->toBe([1, "echo: message $i"]);
        }
        $bytes = \random_bytes(1000);
        ws_send($conn, $bytes, 2);
        expect(ws_read($conn))->toBe([2, \strrev($bytes)]);
        ws_send($conn, 'ünïcödé');
        expect(ws_read($conn))->toBe([1, 'echo: ünïcödé']);
        ws_send($conn, \pack('n', 1000), 8);
        expect(ws_close_code($conn))->toBe(1000);
        \fclose($conn);
    }, workers: 1);
    expect($log)->not->toMatch('/error|exception/i');
});

test('refusal: an ordinary GET to a WebSocket route is answered 426', function () {
    $log = with_app(function (string $addr) {
        $r = (new Browser($addr))->get('/ws');
        expect($r['status'])->toBe(426)
            ->and($r['headers']['upgrade'][0])->toBe('websocket')
            ->and($r['body'])->toBe('This address speaks WebSocket');
    });
    expect($log)->toContain('GET /ws 426')->not->toMatch('/error|exception/i');
});

test('server push: a route that only forwards Swerve::subscribe(), fed by an ordinary route calling Swerve::publish(), in both workers', function () {
    $log = with_app(function (string $addr) {
        [$conns, $pids] = ws_subscribers($addr, 16);
        expect($pids)->toHaveCount(2); // new connections: the kernel spreads them
        // Published by one request: every client gets them in order
        $b = new Browser($addr);
        expect($b->get('/publish?m=news&n=20')['body'])->toBe('published');
        foreach ($conns as $conn) {
            for ($i = 1; $i <= 20; ++$i) {
                expect(ws_read($conn))->toBe([1, "news $i"]);
            }
        }
        // Published by requests in either worker: every client gets them all, in the same order
        for ($i = 1; $i <= 20; ++$i) {
            $b->get("/publish?m=more+$i");
        }
        $orders = [];
        foreach ($conns as $conn) {
            $order = [];
            for ($i = 1; $i <= 20; ++$i) {
                $order[] = ws_read($conn)[1];
            }
            $orders[\implode(',', $order)] = true;
            \sort($order, \SORT_NATURAL);
            expect($order)->toBe(\array_map(fn ($i) => "more $i", \range(1, 20)));
        }
        expect($orders)->toHaveCount(1);
        foreach ($conns as $conn) {
            \fclose($conn);
        }
    });
    expect($log)->not->toMatch('/error|exception/i');
});

test('clients leaving, silently or with a close frame, end every callback', function () {
    $log = with_app(function (string $addr) {
        [$conns] = ws_subscribers($addr, 16);
        $live    = fn () => (int) (new Browser($addr))->get('/ws/live')['body'];
        expect($live())->toBe(16);
        foreach ($conns as $i => $conn) {
            if ($i % 2) {
                ws_send($conn, \pack('n', 1000), 8);
                expect(ws_close_code($conn))->toBe(1000);
            }
            \fclose($conn);
        }
        expect(eventually($live, 0))->toBe(0);
        // Publishing to nobody is fine
        expect((new Browser($addr))->get('/publish?m=late')['body'])->toBe('published');
    });
    expect($log)->not->toMatch('/error|exception/i');
});

test('identity: the user taken before WebSocket::from() is the socket\'s own; Laravel inside the callback is not, unless in Handler::run()', function () {
    $log = with_app(function (string $addr) {
        $ann = new Browser($addr);
        $bob = new Browser($addr);
        expect($ann->get('/login-as/ann')['body'])->toBe('ann')
            ->and($bob->get('/login-as/bob')['body'])->toBe('bob');
        $cookie = fn (Browser $b) => \implode('; ', \array_map(fn ($k, $v) => "$k=$v", \array_keys($b->cookies), $b->cookies));
        $annWs  = ws_connect($addr, '/ws/me', $cookie($ann));
        $bobWs  = ws_connect($addr, '/ws/me', $cookie($bob));
        $anonWs = ws_connect($addr, '/ws/me');

        // The callback runs outside any request, and its application is gone
        $gone = LogicException::class;
        foreach ([[$annWs, 'ann'], [$bobWs, 'bob'], [$anonWs, null]] as [$conn, $name]) {
            ws_send($conn, 'hi');
            expect(\json_decode(ws_read($conn)[1], true))->toBe(['user' => $name, 'auth' => $gone, 'db' => $gone, 'run' => $name, 'message' => 'hi']);
        }

        // While bob's ordinary request runs, ann's callback still has no application of its own, and
        // Handler::run() waits for its turn, unless the worker serves requests at once (phasync-ext)
        $concurrent = 'true' === (new Browser($addr))->get('/concurrent')['body'];
        $multi      = \curl_multi_init();
        $slow       = \curl_init("http://$addr/slow-me?s=1");
        \curl_setopt_array($slow, $bob->options('GET', [], null, $unused));
        \curl_multi_add_handle($multi, $slow);
        $start = \microtime(true);
        while (\microtime(true) - $start < 0.3) {
            \curl_multi_exec($multi, $running);
            \curl_multi_select($multi, 0.02);
        }
        ws_send($annWs, 'during');
        expect(\json_decode(ws_read($annWs)[1], true))->toBe(['user' => 'ann', 'auth' => $gone, 'db' => $gone, 'run' => 'ann', 'message' => 'during'])
            ->and(\microtime(true) - $start)->{$concurrent ? 'toBeLessThan' : 'toBeGreaterThan'}($concurrent ? 0.7 : 0.9);
        do {
            \curl_multi_exec($multi, $running);
            \curl_multi_select($multi, 0.05);
        } while ($running > 0);
        expect(\curl_multi_getcontent($slow))->toBe('bob');

        foreach ([$annWs, $bobWs, $anonWs] as $conn) {
            \fclose($conn);
        }
    }, workers: 1);
    expect($log)->not->toMatch('/error|exception/i');
});

test('the worker keeps serving: 250 open sockets forwarding a subscription hold no Laravel turn', function () {
    $log = with_app(function (string $addr) {
        [$conns] = ws_subscribers($addr, 250);
        $echo    = ws_connect($addr, '/ws');
        $b       = new Browser($addr);
        $times   = [];
        for ($i = 0; $i < 20; ++$i) {
            $r       = $b->get('/json');
            $times[] = $r['time'];
            expect($r['status'])->toBe(200);
            ws_send($echo, "between $i");
            expect(ws_read($echo))->toBe([1, "echo: between $i"]);
        }
        expect(\max($times))->toBeLessThan(0.25)
            ->and((int) $b->get('/ws/live')['body'])->toBe(251);
        // And every socket still gets what is published
        $b->get('/publish?m=all');
        foreach ($conns as $conn) {
            expect(ws_read($conn))->toBe([1, 'all']);
            \fclose($conn);
        }
        \fclose($echo);
    }, workers: 1);
    expect($log)->not->toMatch('/error|exception/i');
});

test('drain: SIGTERM closes open sockets with 1001, ends every callback, exits 0', function () {
    [$proc, $addr, $log] = app_start(2);
    [$conns]             = ws_subscribers($addr, 4);
    for ($i = 0; $i < 4; ++$i) {
        $conns[] = $conn = ws_connect($addr, '/ws');
        ws_send($conn, 'ready');
        expect(ws_read($conn))->toBe([1, 'echo: ready']);
    }
    \proc_terminate($proc, \SIGTERM);
    foreach ($conns as $conn) {
        expect(ws_close_code($conn))->toBe(1001);
        \fclose($conn);
    }
    expect(app_wait($proc))->toBe(0)
        ->and(\glob(APP . '/storage/ws-live/*'))->toBe([])
        ->and(\file_get_contents($log))->not->toMatch('/error|exception|died/i');
    \unlink($log);
});
