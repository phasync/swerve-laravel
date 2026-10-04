<?php

/*
 * Interleaving: requests overlapping in one worker, each setting request state, waiting 0.1 s
 * mid-request (another request runs meanwhile, when the worker lets it), and reading it back.
 * The routes are the /probe* routes of tests/Fixtures/routes/swerve-tests.php; docs/concurrency.md
 * says which of these leak when requests are not kept apart.
 */

/** Four overlapping requests to each path; the bodies, in order. */
function overlap(string $addr, array $paths, ?Closure $prepare = null): array
{
    $requests = [];
    foreach ($paths as $i => $path) {
        $browser = new Browser($addr);
        $prepare && $prepare($browser, $i);
        $requests[] = [$browser, $path];
    }

    return [\array_column(overlapping($requests), 'body'), \array_column($requests, 0)];
}

test('overlapping requests in one worker each keep their own request state', function () {
    $log = with_app(function (string $addr) {
        $tags = ['u0', 'u1', 'u2', 'u3'];
        $read = fn (array $bodies) => \array_map(fn ($b) => \json_decode($b, true)['read'] ?? $b, $bodies);
        $seen = $want = [];
        foreach (['request', 'route', 'container', 'scoped', 'config', 'locale', 'session', 'url', 'view', 'req-query', 'req-route', 'req-session'] as $item) {
            [$bodies, $browsers] = overlap($addr, \array_map(fn ($t) => "/probe/$item/$t?q=$t", $tags));
            $seen[$item]         = $read($bodies);
            $want[$item]         = $tags;
            if ('session' === $item) { // what each session kept, under its own cookie
                $seen['session kept'] = \array_map(fn (Browser $b) => $b->json('/probe/stored/x?wait=0')['read'], $browsers);
                $want['session kept'] = $tags;
            }
        }
        // The logged-in user, from each browser's own session
        foreach (['auth', 'req-user'] as $item) {
            [$bodies]    = overlap($addr, \array_map(fn ($t) => "/probe/$item/$t", $tags), fn (Browser $b, int $i) => $b->get("/login-as/$tags[$i]"));
            $seen[$item] = $read($bodies);
            $want[$item] = $tags;
        }
        // Carbon's locale: the month's name in each request's locale
        [$bodies]        = overlap($addr, ['/probe/carbon/nb', '/probe/carbon/de', '/probe/carbon/fr', '/probe/carbon/es']);
        $seen['carbon']  = $read($bodies);
        $want['carbon']  = ['januar', 'Januar', 'janvier', 'enero'];
        // Output: what a view renders, and what a route echoes, around a wait. PHP's output buffers are the
        // process's, so only phasync-ext's virtualize() keeps them apart. A route's echo is stray output, which
        // without phasync-ext ends the worker: each response is its route's own, and nothing of another's
        $ext             = 'true' === (new Browser($addr))->get('/concurrent')['body'];
        [$bodies]        = overlap($addr, \array_map(fn ($t) => "/probe/blade/$t", $tags));
        $seen['blade']   = $read($bodies);
        $want['blade']   = \array_map(fn ($t) => "$t-$t", $tags);
        if ($ext) {
            [$seen['echo']] = overlap($addr, \array_map(fn ($t) => "/probe-echo/$t", $tags));
            $want['echo']   = \array_map(fn ($t) => "$t-$t", $tags); // the echo is the response: the returned tag is dropped
        } else {
            unset($seen['blade'], $want['blade']);
        }
        // A queued cookie goes with its own request's response only
        [, $browsers]    = overlap($addr, \array_map(fn ($t) => "/probe-cookie/$t", $tags));
        $seen['cookie']  = \array_map(fn (Browser $b) => \implode(',', \array_keys(\array_filter($b->cookies, fn ($k) => \str_starts_with($k, 'probe_'), \ARRAY_FILTER_USE_KEY))), $browsers);
        $want['cookie']  = \array_map(fn ($t) => "probe_$t", $tags);
        // A write made while another request's transaction is open is not part of it
        $key             = 'probe-' . \bin2hex(\random_bytes(4));
        [$bodies]        = overlap($addr, ['/probe-db/rollback', "/probe-db/write/$key"]);
        $seen['db']      = [$bodies[0], $bodies[1], (new Browser($addr))->json("/probe-db/exists/$key")];
        $want['db']      = ['{"level":1}', '{"level":0}', ['exists' => true]];

        expect($seen)->toBe($want);
    }, workers: 1);
    expect($log)->not->toMatch('/error|exception/i');
});

test('requests waiting in a coroutine overlap in one worker, and with phasync-ext so do those in usleep()', function () {
    with_app(function (string $addr) {
        $concurrent = 'true' === (new Browser($addr))->get('/concurrent')['body'];
        foreach (['wait' => true, 'usleep' => $concurrent] as $route => $overlaps) {
            $start    = \microtime(true);
            [$bodies] = overlap($addr, \array_fill(0, 8, "/api/$route?ms=200"));
            $elapsed  = \microtime(true) - $start;
            expect($bodies)->toBe(\array_fill(0, 8, '{"waited":200}'))
                ->and($elapsed)->{$overlaps ? 'toBeLessThan' : 'toBeGreaterThan'}($overlaps ? 0.8 : 1.6);
        }
    }, workers: 1);
});
