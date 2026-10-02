<?php

/*
 * Every request builds its own Application, as php-fpm does. These tests look at what that means
 * for the application's class, and for the static state Eloquent keeps per model class.
 */

test('app() is an instance of the class bootstrap/app.php returned', function () {
    with_app(function (string $addr) {
        expect((new Browser($addr))->json('/app-class'))->toBe(['custom' => true, 'marker' => 'App\CustomApplication']);
    }, workers: 1, env: ['APP_CLASS' => 'App\CustomApplication']);
});

test('a provider that reads the request while booting finds it, as under php-fpm', function () {
    with_app(function (string $addr) {
        expect((new Browser($addr))->json('/root-url')['url'])->toBe('http://'.$addr.'/x');
    }, workers: 1);
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
        $concurrent = 'true' === (new Browser($addr))->get('/concurrent')['body'];
        $requests   = [[new Browser($addr), '/widget/' . \bin2hex(\random_bytes(4)) . '?pre=0.4'], [new Browser($addr), '/widget/' . \bin2hex(\random_bytes(4))]];
        // Eloquent boots a model once per process, and its listeners go to the application that is current then
        expect(\array_map(fn ($r) => \json_decode($r['body'], true), overlapping($requests)))
            ->toBe($concurrent ? [['listener' => null], ['listener' => 1]] : [['listener' => 1], ['listener' => 1]]);
    }, workers: 1);
});
