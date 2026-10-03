<?php

/*
 * Swerve's guard ends a worker on output outside a response (docs/stray-output.md in swerve), and has
 * its own tests; here: with phasync-ext an echo in a route is harmless, and without it the routes
 * of the skeleton print nothing. The echoing routes are in tests/Fixtures/routes/stray-output.php,
 * which no other test requests.
 */

test('with phasync-ext a route that echoes answers with its own response, and no worker dies', function () {
    [$proc, $addr, , $err] = app_start(1);
    try {
        $browser = new Browser($addr);
        $pid     = $browser->json('/counter')['pid'];
        $r       = $browser->get('/echo');
        expect($r['status'])->toBe(200)
            ->and($r['body'])->toBe('body')
            ->and($r['headers']['content-type'])->toBe(['text/html; charset=utf-8']);
        // Output before an exception is not part of the error page
        $r = $browser->get('/echo-throw');
        expect($r['status'])->toBe(500)
            ->and($r['body'])->not->toStartWith('echoed before failing');
        // Output around a wait: the response comes when the route returns it, with its own headers, also when requests overlap
        $tags      = ['a', 'b', 'c', 'd'];
        $responses = overlapping(\array_map(fn ($t) => [new Browser($addr), "/echo-wait/$t"], $tags));
        expect(\array_column($responses, 'status'))->toBe([200, 200, 200, 200])
            ->and(\array_map(fn ($r) => $r['headers']['content-type'], $responses))->each->toBe(['application/json'])
            ->and(\array_map(fn ($r) => \json_decode($r['body'], true)['tag'], $responses))->toBe($tags);
        // The same worker serves on
        expect($browser->json('/counter')['pid'])->toBe($pid);
    } finally {
        app_stop($proc);
    }
    expect(\file_get_contents($err))->not->toContain('Stray output');
})->skip(fn () => !ext_loaded(), 'needs phasync-ext');

test('the routes of the skeleton print nothing outside their responses: the worker is never respawned', function () {
    [$proc, $addr, $log, $err] = app_start(1);
    try {
        $b   = new Browser($addr);
        $pid = $b->json('/counter')['pid'];
        // A page, JSON, validation errors, 404, a redirect, and the session
        $form = $b->get('/form')['body'];
        \preg_match('/name="_token" value="([^"]+)"/', $form, $m);
        $invalid = $b->request('POST', '/form', ['Referer: http://' . $addr . '/form'], ['_token' => $m[1]]);
        expect($invalid['status'])->toBe(302)
            ->and($b->get('/form')['body'])->toContain('The name field is required.') // the next request only
            ->and($b->get('/')['status'])->toBe(200)
            ->and($b->json('/json')['hello'])->toBe('world')
            ->and($b->get('/missing')['status'])->toBe(404)
            ->and($b->request('GET', '/go-back', ['Referer: http://' . $addr . '/json'])['status'])->toBe(302)
            ->and($b->get('/flash/set')['body'])->toBe('ok')
            ->and($b->get('/flash/show')['body'])->toBe('saved')
            ->and($b->json('/probe/blade/x')['read'])->toBe('x-x');
        // Streams: an echoing callback, an event stream, a download, overlapping streams
        expect($b->get('/stream')['body'])->toMatch('/^first [\d.]+\nlast [\d.]+\n$/')
            ->and($b->get('/sse')['body'])->toContain('tick 3')
            ->and($b->get('/download')['status'])->toBe(200);
        $streams = overlapping(\array_map(fn ($id) => [new Browser($addr), "/stream-id/$id"], ['a', 'b']));
        expect($streams[1]['body'])->toBe('b1 b2 b3 b4 b5 b6 ');
        // A WebSocket
        $conn = ws_connect($addr, '/ws');
        ws_send($conn, 'hello');
        expect(ws_read($conn))->toBe([1, 'echo: hello']);
        \fclose($conn);
        expect($b->json('/counter')['pid'])->toBe($pid);
    } finally {
        app_stop($proc);
    }
    expect(\file_get_contents($err))->not->toContain('Stray output')
        ->and(\file_get_contents($log))->not->toMatch('/error|exception|died/i');
    \unlink($log);
});
