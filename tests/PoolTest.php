<?php

/*
 * Every request runs in an Application of its own, taken from a pool of booted ones. These tests
 * look at the pool, at the application's class, and at the static state Eloquent keeps per model class.
 */

test('app() is an instance of the class bootstrap/app.php returned', function () {
    with_app(function (string $addr) {
        expect((new Browser($addr))->json('/app-class'))->toBe(['custom' => true, 'marker' => 'App\CustomApplication']);
    }, workers: 1, env: ['APP_CLASS' => 'App\CustomApplication']);
});

test('a final application class is refused at start, with the reason', function () {
    expect(fn () => with_app(fn () => null, workers: 1, env: ['APP_CLASS' => 'App\FinalApplication']))
        ->toThrow(RuntimeException::class, 'App\FinalApplication is final');
});

test('a model\'s boot() listener and global scope, and a provider\'s observer, apply on every request', function () {
    with_app(function (string $addr) {
        $run = \bin2hex(\random_bytes(4)); // the rows of earlier runs stay in the database
        $one = fn (string $name, string $query = '') => (new Browser($addr))->json("/gadget/$run-$name?wait=0.05$query");
        $ok  = ['listener' => 1, 'observer' => 1, 'visible' => 1, 'all' => 1];

        // From different requests, one after the other
        $seen = [$one('a1'), $one('a2'), $one('a3')];
        expect($seen)->toBe([$ok, $ok, $ok])
            ->and($one('hidden1', '&hidden=1'))->toBe(['listener' => 1, 'observer' => 1, 'visible' => 0, 'all' => 1]);

        // And overlapping: each request waits while the others boot their own application
        $requests = [];
        foreach (\range(0, 11) as $i) {
            $requests[] = [new Browser($addr), "/gadget/$run-c$i?wait=0.2" . ($i % 3 ? '' : '&hidden=1')];
        }
        $bodies = \array_map(fn ($r) => \json_decode($r['body'], true), overlapping($requests));
        $want   = \array_map(fn ($i) => ['listener' => 1, 'observer' => 1, 'visible' => $i % 3 ? 1 : 0, 'all' => 1], \range(0, 11));
        expect($bodies)->toBe($want)
            ->and($one('after'))->toBe($ok);
    }, workers: 1);
});

// Eloquent boots a model once per process, and registers the listeners of its boot() on the
// application that is current then; every new application forgets which models are booted
// (DatabaseServiceProvider::register()), so a model booted in a provider is booted by each
// application. A model first used mid-request is booted by whichever request uses it first.
test('a model that no provider boots misses its boot() listener in a request that waits while another one boots it', function () {
    with_app(function (string $addr) {
        $requests = [[new Browser($addr), '/widget/' . \bin2hex(\random_bytes(4)) . '?pre=0.4'], [new Browser($addr), '/widget/' . \bin2hex(\random_bytes(4))]];
        // Eloquent boots a model once per process, and its listeners go to the application that is current then
        expect(\array_map(fn ($r) => \json_decode($r['body'], true), overlapping($requests)))
            ->toBe([['listener' => null], ['listener' => 1]]);
    }, workers: 1);
});

test('applications are booted as requests overlap, and dropped after sitting unused', function () {
    with_app(function (string $addr) {
        $boots = fn () => (new Browser($addr))->json('/boots')['boots'];
        $start = $boots();
        $burst = \array_map(fn ($i) => [new Browser($addr), '/api/wait?ms=300'], \range(1, 6));
        overlapping($burst);
        $booted = $boots() - $start;
        expect($booted)->toBeGreaterThan(1);

        // Used again soon: the same applications serve, nothing is booted
        overlapping($burst);
        expect($boots() - $start)->toBe($booted);

        // Unused for longer than the idle time: they were dropped, a request boots a new one
        \usleep(1_500_000);
        expect($boots() - $start)->toBeGreaterThan($booted);
    }, workers: 1, env: ['APP_IDLE_SECONDS' => '0.6']);
});
