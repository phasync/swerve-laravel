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
