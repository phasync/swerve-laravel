<?php

test('the skeleton: home page, JSON, 404, echoed output', function () {
    $log = with_app(function (string $addr) {
        $b = new Browser($addr);
        expect($b->get('/'))->toMatchArray(['status' => 200])
            ->and($b->get('/')['body'])->toContain('Laravel')
            ->and($b->json('/json'))->toMatchArray(['hello' => 'world'])
            ->and($b->get('/missing')['status'])->toBe(404)
            ->and($b->get('/echo')['body'])->toBe('echoed body');
    });
    expect($log)->toContain('GET /json 200')->not->toMatch('/error|exception/i');
});

test('a form POST with CSRF protection, validation errors through the session', function () {
    with_app(function (string $addr) {
        $b    = new Browser($addr);
        $form = $b->get('/form')['body'];
        expect($form)->toMatch('/name="_token" value="([^"]+)"/');
        \preg_match('/name="_token" value="([^"]+)"/', $form, $m);

        expect($b->request('POST', '/form', [], ['name' => 'Ada'])['status'])->toBe(419)
            ->and($b->request('POST', '/form', [], ['_token' => $m[1], 'name' => 'Ada']))->toMatchArray(['status' => 200, 'body' => 'Hello Ada']);
        $invalid = $b->request('POST', '/form', ['Referer: http://' . $addr . '/form'], ['_token' => $m[1]]);
        expect($invalid['status'])->toBe(302)
            ->and($b->get('/form')['body'])->toContain('The name field is required.')
            ->and($b->get('/form')['body'])->not->toContain('The name field is required.');
    });
});

test('a JSON POST, a url-encoded PUT, and a route taking a PSR-7 request', function () {
    with_app(function (string $addr) {
        $b    = new Browser($addr);
        $json = $b->request('POST', '/json-echo', ['Content-Type: application/json'], '{"a":1,"b":{"c":[2,3]}}');
        expect(\json_decode($json['body'], true))->toBe(['all' => ['a' => 1, 'b' => ['c' => [2, 3]]], 'isJson' => true, 'method' => 'POST']);
        $put = $b->request('PUT', '/json-echo', ['Content-Type: application/x-www-form-urlencoded'], 'x=1&y[]=2');
        expect(\json_decode($put['body'], true))->toBe(['all' => ['x' => '1', 'y' => ['2']], 'isJson' => false, 'method' => 'PUT']);
        // Swerve's own request: a form is parsed, other bodies are read as they are
        expect(\json_decode($b->request('POST', '/psr-echo', [], ['a' => '1'])['body'], true)['parsed'])->toBe(['a' => '1'])
            ->and(\json_decode($b->request('POST', '/psr-echo', ['Content-Type: application/json'], '{"a":1}')['body'], true)['body'])->toBe('{"a":1}');
    });
});

test('uploads: validated, stored, moved, and their temporary files deleted', function () {
    with_app(function (string $addr) {
        $doc = \tempnam(\sys_get_temp_dir(), 'doc');
        \file_put_contents($doc, $bytes = \random_bytes(300_000));
        $other = \tempnam(\sys_get_temp_dir(), 'other');
        \file_put_contents($other, 'moved bytes');
        $r = (new Browser($addr))->request('POST', '/upload', [], [
            'doc'   => new CURLFile($doc, 'application/octet-stream', 'report.bin'),
            'other' => new CURLFile($other, 'text/plain', 'other.txt'),
        ]);
        \unlink($doc);
        \unlink($other);
        $out = \json_decode($r['body'], true);
        expect($r['status'])->toBe(200)
            ->and($out)->toMatchArray(['name' => 'report.bin', 'size' => 300_000, 'md5' => \md5($bytes), 'valid' => true, 'moved' => 'moved bytes'])
            ->and(\md5_file(APP . '/storage/app/private/' . $out['stored']))->toBe(\md5($bytes));
        \unlink(APP . '/storage/app/private/' . $out['stored']);
        // Deleted when the request is over, which is just after the response
        $deadline = \microtime(true) + 2;
        while ((\file_exists($out['tmp'][0]) || \file_exists($out['tmp'][1])) && \microtime(true) < $deadline) {
            \usleep(20_000);
        }
        expect(\file_exists($out['tmp'][0]))->toBeFalse()->and(\file_exists($out['tmp'][1]))->toBeFalse();
    });
});

test('isolation: overlapping requests in one worker each see only their own request, route, session, user, scoped service and config', function () {
    with_app(function (string $addr) {
        $browsers = [];
        for ($i = 0; $i < 10; ++$i) {
            $browsers[$i] = new Browser($addr);
            $browsers[$i]->get('/counter'); // a session of its own
        }
        $requests = [];
        foreach ($browsers as $i => $b) {
            $requests[] = [$b, "/isolation/t$i?q=t$i"];
        }
        $requests[] = [$fresh = new Browser($addr), '/isolation/fresh?q=fresh']; // no session cookie
        $responses  = overlapping($requests);
        foreach ($responses as $i => $r) {
            $tag = 10 === $i ? 'fresh' : "t$i";
            expect($r['status'])->toBe(200)
                ->and(\json_decode($r['body'], true))->toBe([
                    'before' => ['session' => null, 'user' => null, 'scoped' => null, 'config' => null],
                    'after'  => ['query' => $tag, 'route' => $tag, 'attr' => $tag, 'session' => $tag, 'user' => $tag, 'scoped' => $tag, 'config' => $tag],
                ]);
        }
        // Each session kept its own value, under its own cookie
        foreach ([...$browsers, 10 => $fresh] as $i => $b) {
            expect($b->json('/isolation/x?wait=0')['before'])->toBe(['session' => 10 === $i ? 'fresh' : "t$i", 'user' => null, 'scoped' => null, 'config' => null]);
        }
    }, workers: 1);
});

test('sessions: a counter across workers, a flash message shown once, login and logout', function (string $driver) {
    with_app(function (string $addr) use ($driver) {
        $b    = new Browser($addr);
        $pids = [];
        for ($i = 1; $i <= 20; ++$i) {
            $r = $b->json('/counter');
            expect($r['n'])->toBe($i)->and($r['driver'])->toBe($driver);
            $pids[$r['pid']] = true;
        }
        expect(\count($pids))->toBe(2) // every request on a new connection: the kernel spreads them
            ->and((new Browser($addr))->json('/counter')['n'])->toBe(1);

        expect($b->get('/flash/set')['body'])->toBe('ok')
            ->and($b->get('/flash/show')['body'])->toBe('saved')
            ->and($b->get('/flash/show')['body'])->toBe('none');

        $login = $b->json('/login');
        expect($login['ok'])->toBeTrue()->and($login['id'])->toBeInt();
        for ($i = 0; $i < 4; ++$i) {
            expect($b->json('/me'))->toBe(['id' => $login['id']]);
        }
        expect((new Browser($addr))->json('/me'))->toBe(['id' => null])
            ->and($b->json('/logout'))->toBe(['id' => null])
            ->and($b->json('/me'))->toBe(['id' => null]);
    }, env: ['SESSION_DRIVER' => $driver]);
})->with(['database', 'file', 'cookie']);

test('streaming: a streamed response and Server-Sent Events arrive as they are produced', function () {
    with_app(function (string $addr) {
        $chunks = [];
        $ch     = \curl_init("http://$addr/stream");
        \curl_setopt($ch, \CURLOPT_WRITEFUNCTION, function ($ch, string $data) use (&$chunks) {
            $chunks[] = [\microtime(true), $data];

            return \strlen($data);
        });
        \curl_exec($ch);
        $body = \implode('', \array_column($chunks, 1));
        expect($body)->toMatch('/^first [\d.]+\nlast [\d.]+\n$/');
        // The first line arrived before the last was produced, half a second later
        expect($chunks[0][1])->toStartWith('first')
            ->and(\end($chunks)[0] - $chunks[0][0])->toBeGreaterThan(0.4);

        $sse = (new Browser($addr))->get('/sse');
        expect($sse['headers']['content-type'][0])->toStartWith('text/event-stream')
            ->and($sse['body'])->toBe("event: update\ndata: tick 1\n\nevent: update\ndata: tick 2\n\nevent: update\ndata: tick 3\n\nevent: update\ndata: </stream>\n\n");

        $head = (new Browser($addr))->request('HEAD', '/stream');
        expect($head['status'])->toBe(200)->and($head['body'])->toBe('')->and($head['time'])->toBeLessThan(0.3);
    });
});

test('streaming: a client leaving ends an endless stream, and the worker serves on', function () {
    \is_file(APP . '/storage/forever-ended') && \unlink(APP . '/storage/forever-ended');
    $log = with_app(function (string $addr) {
        $conn = \stream_socket_client("tcp://$addr");
        \fwrite($conn, "GET /forever HTTP/1.1\r\nHost: test\r\n\r\n");
        expect(\fread($conn, 8192))->toStartWith('HTTP/1.1 200');
        \usleep(200_000);
        \fclose($conn);
        $deadline = \microtime(true) + 3;
        while (!\file_exists(APP . '/storage/forever-ended') && \microtime(true) < $deadline) {
            \usleep(20_000);
        }
        expect(\file_exists(APP . '/storage/forever-ended'))->toBeTrue()
            ->and((new Browser($addr))->json('/json'))->toMatchArray(['hello' => 'world']);
    }, workers: 1);
    expect($log)->not->toMatch('/error|exception/i');
});

test('file responses, with ranges', function () {
    with_app(function (string $addr) {
        $b    = new Browser($addr);
        $file = \file_get_contents(APP . '/composer.json');
        expect($b->get('/download'))->toMatchArray(['status' => 200, 'body' => $file])
            ->and($b->get('/download', ['Range: bytes=2-11']))->toMatchArray(['status' => 206, 'body' => \substr($file, 2, 10)]);
    });
});

test('terminate() and defer() run after the response was sent', function () {
    \is_file(APP . '/storage/deferred') && \unlink(APP . '/storage/deferred');
    with_app(function (string $addr) {
        $r = (new Browser($addr))->get('/defer');
        expect($r['status'])->toBe(200)
            ->and($r['time'])->toBeLessThan(0.5)
            ->and(\file_exists(APP . '/storage/deferred'))->toBeFalse();
        $deadline = \microtime(true) + 3;
        while (!\file_exists(APP . '/storage/deferred') && \microtime(true) < $deadline) {
            \usleep(20_000);
        }
        expect((float) \file_get_contents(APP . '/storage/deferred') - (float) $r['body'])->toBeGreaterThan(0.6);
    });
});

test('drain: SIGTERM lets a slow request finish, and defer() work after a response, without errors', function () {
    // SIGTERM while a request runs
    [$proc, $addr, $log] = app_start(1);
    $multi               = \curl_multi_init();
    $slow                = \curl_init("http://$addr/slow?s=1");
    \curl_setopt($slow, \CURLOPT_RETURNTRANSFER, true);
    \curl_multi_add_handle($multi, $slow);
    $start = \microtime(true);
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.02);
        if (isset($start) && \microtime(true) - $start > 0.3) {
            \proc_terminate($proc, \SIGTERM);
            unset($start);
        }
    } while ($running > 0);
    expect(\curl_multi_getcontent($slow))->toBe('slow done')
        ->and(app_wait($proc))->toBe(0)
        ->and(\file_get_contents($log))->toContain('GET /slow?s=1 200')->not->toMatch('/error|exception|died/i');
    \unlink($log);

    // SIGTERM after a response, while its defer() callback works for 0.7 s
    \is_file(APP . '/storage/deferred') && \unlink(APP . '/storage/deferred');
    [$proc, $addr, $log] = app_start(1);
    expect((new Browser($addr))->get('/defer')['status'])->toBe(200)
        ->and(app_stop($proc))->toBe(0)
        ->and(\file_exists(APP . '/storage/deferred'))->toBeTrue()
        ->and(\file_get_contents($log))->not->toMatch('/error|exception|died/i');
    \unlink($log);
});

test('memory stays flat over 10,000 requests', function () {
    with_app(function (string $addr) {
        $ch = \curl_init();
        \curl_setopt_array($ch, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_COOKIEFILE => '']); // one keep-alive connection and session
        $get = function (string $path) use ($ch, $addr) {
            \curl_setopt($ch, \CURLOPT_URL, "http://$addr$path");

            return \curl_exec($ch);
        };
        // With phasync-ext's virtualize(), a worker's memory grows by about 0.7 MiB over its first
        // 4,000 requests, and then stays
        for ($i = 0; $i < 5_000; ++$i) {
            $get('/json');
        }
        $before = (int) $get('/memory');
        for ($i = 0; $i < 5_000; ++$i) {
            $get('/json');
        }
        $after = (int) $get('/memory');
        expect($after - $before)->toBeLessThan(512 * 1024);
    }, workers: 1);
});
