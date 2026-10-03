<?php

/*
 * Streamed responses that overlap: each client gets what its own callback echoed, and nothing else.
 * Output buffers are the worker's without phasync-ext, which keeps each request's apart.
 */

test('concurrent streams: each client sees only what its own callback echoed', function () {
    with_app(function (string $addr) {
        $ids       = ['a', 'b', 'c'];
        $responses = overlapping(\array_map(fn ($id) => [new Browser($addr), "/stream-id/$id"], $ids));
        foreach ($ids as $i => $id) {
            expect($responses[$i]['body'])->toBe(\implode('', \array_map(fn ($n) => "$id$n ", \range(1, 6))));
        }
    }, workers: 1);
});

test('overlapping streams all complete, and a request that is not a stream is not held up behind them', function () {
    with_app(function (string $addr) {
        $multi     = \curl_multi_init();
        $handles   = [];
        foreach (['a', 'b', 'c'] as $id) {
            $handles[$id] = \curl_init("http://$addr/stream-id/$id");
            \curl_setopt($handles[$id], \CURLOPT_RETURNTRANSFER, true);
            \curl_multi_add_handle($multi, $handles[$id]);
        }
        \curl_multi_exec($multi, $running);
        \usleep(200_000);
        // A request that is not a stream is answered at once, however many streams are running or waiting
        $took = (new Browser($addr))->get('/json')['time'];
        expect($took)->toBeLessThan(0.3);
        do {
            \curl_multi_exec($multi, $running);
            \curl_multi_select($multi, 0.1);
        } while ($running > 0);
        foreach ($handles as $id => $ch) {
            expect(\curl_multi_getcontent($ch))->toBe(\implode('', \array_map(fn ($n) => "$id$n ", \range(1, 6))));
        }
    }, workers: 1);
});
