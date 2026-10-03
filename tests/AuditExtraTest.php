<?php

/*
 * Flow probes of the contamination audit: terminable middleware, route-model binding, validation,
 * response cookies, login. Two requests overlap (A waits inside, B starts meanwhile and finishes
 * first), then sequential requests follow. Routes: tests/Fixtures/routes/audit-extra.php.
 */

require_once __DIR__ . '/Audit/overlap.php';

test('terminable middleware runs once per request, with that request and its application', function () {
    $log = \dirname(__DIR__) . '/tests/Fixtures/app/storage/audz-term.log';
    @\unlink($log);
    with_app(function (string $addr) use ($log) {
        [$a, $b] = audr_overlap([[new Browser($addr), '/audz-term/A?wait=0.8', 0], [new Browser($addr), '/audz-term/B?wait=0.1', 0.3]]);
        audr_nested($a, $b);
        foreach (['C', 'D'] as $t) {
            (new Browser($addr))->get("/audz-term/$t?wait=0");
        }
        \usleep(300000);
        $lines = \array_map(fn ($l) => \json_decode($l, true), \file($log, \FILE_IGNORE_NEW_LINES));
        \usort($lines, fn ($x, $y) => $x['url'] <=> $y['url']);
        expect($lines)->toBe(\array_map(fn ($t) => ['url' => $t, 'header' => $t, 'same' => true, 'sameApp' => true, 'current' => $t, 'user' => null], ['A', 'B', 'C', 'D']));
    }, workers: 1);
});

test('route-model binding that waits keeps each request its own parameter', function () {
    with_app(function (string $addr) {
        [$a, $b] = audr_overlap([[new Browser($addr), '/audz-bind/A', 0], [new Browser($addr), '/audz-bind/B', 0.3]]);
        // B's window falls inside A's resolving of the binding
        expect($b['start'] < $a['start'] + 0.8 + 0.3)->toBeTrue();
        expect([$a['param'], $a['route'], $a['current'], $a['path']])->toBe(['A', 'A', 'A', 'audz-bind/A']);
        expect([$b['param'], $b['route'], $b['current'], $b['path']])->toBe(['B', 'B', 'B', 'audz-bind/B']);
        $c = (new Browser($addr))->json('/audz-bind/C');
        expect([$c['param'], $c['route'], $c['current']])->toBe(['C', 'C', 'C']);
    }, workers: 1);
});

test('validation failures that wait keep each request its own errors', function () {
    with_app(function (string $addr) {
        [$a, $b] = audr_overlap([[new Browser($addr), '/audz-validate/A', 0], [new Browser($addr), '/audz-validate/B', 0.3]]);
        audr_nested($a, $b);
        expect([$a['message'], $a['bag']])->toBe(['bad-A', ['bad-A']]);
        expect([$b['message'], $b['bag']])->toBe(['bad-B', ['bad-B']]);
        $c = (new Browser($addr))->json('/audz-validate/C');
        expect($c['bag'])->toBe(['bad-C']);
    }, workers: 1);
});

test('cookies and headers put on a response reach only that response', function () {
    with_app(function (string $addr) {
        $ba = new Browser($addr);
        $bb = new Browser($addr);
        [$a, $b] = audr_overlap([[$ba, '/audz-cookie/A?wait=0.8', 0], [$bb, '/audz-cookie/B?wait=0.1', 0.3]]);
        audr_nested($a, $b);
        $names = fn (Browser $br) => \array_values(\array_filter(\array_keys($br->cookies), fn ($n) => \str_starts_with($n, 'audz_')));
        expect($names($ba))->toBe(['audz_A'])->and($names($bb))->toBe(['audz_B']);
        $c = new Browser($addr);
        $r = $c->get('/audz-cookie/C?wait=0');
        expect($names($c))->toBe(['audz_C'])->and(\array_keys($r['headers']))->not->toContain('x-audz-a', 'x-audz-b');
    }, workers: 1);
});

test('a login in one request is not another request\'s user', function () {
    with_app(function (string $addr) {
        $anon = new Browser($addr);
        $bob  = new Browser($addr);
        $bob->get('/login-as/Bob');
        $ann = new Browser($addr);
        // Ann logs in while Bob's slow request is in flight; the anonymous request starts after her login
        [$slow, $login, $guest] = audr_overlap([
            [$bob, '/audz-who?wait=1', 0],
            [$ann, '/login-as/Ann', 0.2],
            [$anon, '/audz-who?wait=0.1', 0.5],
        ]);
        expect($slow['before'])->toBe([true, 'Bob', 'Bob'])->and($slow['after'])->toBe([true, 'Bob', 'Bob', 'Bob']);
        expect($guest['before'])->toBe([false, null, null])->and($guest['after'])->toBe([false, null, null, null]);
        expect($ann->json('/audz-who?wait=0')['before'])->toBe([true, 'Ann', 'Ann']);
        expect((new Browser($addr))->json('/audz-who?wait=0')['before'])->toBe([false, null, null]);
    }, workers: 1);
});
