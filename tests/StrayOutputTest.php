<?php

/*
 * What a route echoes outside the response it returns: today the handler captures it around the
 * kernel and puts it before the response's content, as under PHP-FPM.
 */

test('a route that echoes and returns a response: the output goes before the content', function () {
    with_app(function (string $addr) {
        $r = (new Browser($addr))->get('/echo');
        expect($r['status'])->toBe(200)
            ->and($r['body'])->toBe('echoed body');
    }, workers: 1);
});

test('a route that echoes and then throws: the output goes before the error page, and the worker serves on', function () {
    with_app(function (string $addr) {
        $browser = new Browser($addr);
        $r       = $browser->get('/echo-throw');
        expect($r['status'])->toBe(500)
            ->and($r['body'])->toStartWith('echoed before failing<!DOCTYPE html>');
        // The same worker, the same way, afterwards
        expect($browser->get('/echo')['body'])->toBe('echoed body')
            ->and($browser->json('/json')['hello'])->toBe('world');
    }, workers: 1);
});

/*
 * Output buffers are the worker's, not the request's, unless phasync-ext's virtualize() is there:
 * the handler captures nothing. Output a route echoes outside its response goes to the process's
 * stdout without phasync-ext, and nowhere a client can see it with it; the response is the one
 * the route returned, with its headers.
 */

test('output a route echoes is not part of the response it returns', function () {
    with_app(function (string $addr) {
        $r = (new Browser($addr))->get('/echo');
        expect($r['status'])->toBe(200)
            ->and($r['body'])->toBe('body')
            ->and($r['headers']['content-type'])->toBe(['text/html; charset=utf-8']);
    }, workers: 1);
});

test('output a route echoes before it throws is not part of the error page', function () {
    with_app(function (string $addr) {
        $browser = new Browser($addr);
        $r       = $browser->get('/echo-throw');
        expect($r['status'])->toBe(500)
            ->and($r['body'])->not->toStartWith('echoed before failing')
            ->and($browser->get('/echo')['body'])->toBe('body');
    }, workers: 1);
});

test('a route that echoes and then waits still answers with its own response and headers, also when requests overlap', function () {
    with_app(function (string $addr) {
        $tags      = ['a', 'b', 'c', 'd'];
        $responses = overlapping(\array_map(fn ($t) => [new Browser($addr), "/echo-wait/$t"], $tags));
        expect(\array_column($responses, 'status'))->toBe([200, 200, 200, 200])
            ->and(\array_map(fn ($r) => $r['headers']['content-type'], $responses))->each->toBe(['application/json'])
            ->and(\array_map(fn ($r) => \json_decode($r['body'], true)['tag'], $responses))->toBe($tags);
        // The worker serves on
        expect((new Browser($addr))->json('/json')['hello'])->toBe('world');
    }, workers: 1);
});
