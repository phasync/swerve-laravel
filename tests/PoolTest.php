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

// Starting leaves two applications idle: the one the readiness probe used, and the spare that the probe's taking
// of the last idle application booted
test('a request that takes the last idle application leaves a spare booting, so the next request finds one ready', function () {
    with_app(function (string $addr) {
        \usleep(300_000); // the startup spare is booted
        $boots = fn () => (new Browser($addr))->json('/boots')['boots'];
        $start = $boots();
        overlapping(\array_map(fn () => [new Browser($addr), '/api/wait?ms=300'], [1, 2]));
        // Two overlapping requests take the two idle applications: nothing is booted for them, and one spare when the last was taken
        expect($boots() - $start)->toBe(1);
    }, workers: 1);
});

test('a burst boots the applications it needs, and one spare', function () {
    with_app(function (string $addr) {
        $boots = fn () => (new Browser($addr))->json('/boots')['boots'];
        $start = $boots();
        overlapping(\array_map(fn () => [new Browser($addr), '/api/wait?ms=300'], \range(1, 6)));
        \usleep(500_000);
        // Six at once: four more than the two idle, and one spare (or two, as the startup spare may still be booting)
        expect($boots() - $start)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(6);
    }, workers: 1);
});

test('a terminating callback registered by a request runs for that request only, however long the application is pooled', function () {
    foreach ([[], ['APP_CONTEXT_STATE' => '1']] as $env) {
        $file = APP . '/storage/terminated.log';
        \is_file($file) && \unlink($file);
        with_app(function (string $addr) use ($file) {
            $tags = \array_map(fn ($i) => "s$i", \range(1, 12));
            foreach ($tags as $tag) {
                expect((new Browser($addr))->get("/terminating/$tag")['body'])->toBe($tag);
            }
            $overlapping = \array_map(fn ($i) => "o$i", \range(1, 12));
            overlapping(\array_map(fn ($tag) => [new Browser($addr), "/terminating/$tag?wait=0.2"], $overlapping));
            // Once more per application, so that every one is reset and reused after the overlapping requests
            foreach ($tags as $i => $tag) {
                (new Browser($addr))->get("/terminating/t$i");
            }
            $want = \array_merge($tags, $overlapping, \array_map(fn ($i) => "t$i", \array_keys($tags)));
            \sort($want);
            $got = fn () => \is_file($file) ? \explode("\n", \trim(\file_get_contents($file))) : [];
            expect(eventually(fn () => \count($got()), \count($want)))->toBe(\count($want));
            \usleep(300_000);
            $lines = $got();
            \sort($lines);
            expect($lines)->toBe($want);
        }, workers: 1, env: $env);
        \unlink($file);
    }
});

// The pool is phasync\Util\Pool: at most `maxApplications` exist, the rest of the requests wait
test('with maxApplications 2, five overlapping requests are all served by the two applications that exist', function () {
    with_app(function (string $addr) {
        $start     = \microtime(true);
        $responses = overlapping(\array_map(fn () => [new Browser($addr), '/app-id?ms=300'], \range(1, 5)));
        $took      = \microtime(true) - $start;
        $ids       = [];
        foreach ($responses as $r) {
            expect($r['status'])->toBe(200);
            $ids[\json_decode($r['body'], true)['id']] = true;
        }
        // Two at a time: three rounds of 0.3 s, where five applications would take one
        expect(\count($ids))->toBeLessThanOrEqual(2)
            ->and($took)->toBeGreaterThan(0.85)
            // The worker's first application is not the pool's: it booted the provider once, the pool's two did
            ->and((new Browser($addr))->json('/boots')['boots'])->toBeLessThanOrEqual(3);
    }, workers: 1, env: ['APP_MAX_APPLICATIONS' => '2']);
});

test('applications unused for the idle time are dropped, with no traffic to notice it', function () {
    with_app(function (string $addr) {
        $wave  = fn () => \array_map(fn ($r) => \json_decode($r['body'], true)['id'], overlapping(\array_map(fn () => [new Browser($addr), '/app-id?ms=200'], \range(1, 4))));
        $first = $wave();
        // Used again soon: the applications that exist serve
        expect(\array_intersect($wave(), $first))->not->toBeEmpty();
        \usleep(1_500_000); // the idle time is 0.5 s
        // None of them is left: the four requests were served by applications booted since
        expect(\array_intersect($wave(), $first))->toBe([]);
    }, workers: 1, env: ['APP_IDLE_SECONDS' => '0.5']);
});

test('the spare a wave left is idle when the next wave comes, which pays for no boot', function () {
    with_app(function (string $addr) {
        $wave = fn (int $n) => \array_map(fn ($r) => \json_decode($r['body'], true), overlapping(\array_map(fn () => [new Browser($addr), '/app-id?ms=300'], \range(1, $n))));
        $wave(2);
        \usleep(500_000);
        $booted = (new Browser($addr))->json('/boots')['boots'];
        // Three at once: the two applications of the first wave, and the spare its second request left. An id is the number
        // of its application's boot, so one above $booted would be an application booted for the request itself
        expect(\max(\array_column($wave(3), 'id')))->toBeLessThanOrEqual($booted);
    }, workers: 1);
});

test('a request that fails drops its application, and the worker serves on', function () {
    foreach (['stream', 'terminate'] as $failure) {
        with_app(function (string $addr) use ($failure) {
            $file = APP . '/storage/failed-app-id';
            \is_file($file) && \unlink($file);
            $b = new Browser($addr);
            $r = $b->get("/app-id?fail=$failure");
            if ('stream' === $failure) {
                $victim = (int) \file_get_contents($file);
            } else {
                // The response was sent; terminate() failed after it
                expect($r['status'])->toBe(200);
                $victim = \json_decode($r['body'], true)['id'];
            }
            // One request after the other would reuse an application: the one that failed is not among them
            $ids = \array_map(fn () => $b->json('/app-id')['id'], \range(1, 6));
            expect($ids)->not->toContain($victim)
                ->and(\array_column(overlapping(\array_map(fn () => [$b, '/app-id?ms=100'], \range(1, 3))), 'status'))->toBe([200, 200, 200]);
        }, workers: 1);
    }
});
