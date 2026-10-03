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
