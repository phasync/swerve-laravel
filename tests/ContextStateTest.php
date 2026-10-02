<?php

/*
 * Handler(contextState: true): each pooled application has a phasync::$contextState array of its own,
 * and a request runs with the one of the application that serves it. The fixture application keeps
 * nothing in static properties that are context-local state, so these tests look at the arrays
 * themselves, through routes that use phasync::$contextState as an application would.
 */

const STATE = ['APP_CONTEXT_STATE' => '1'];

beforeEach(function () {
    \property_exists(phasync::class, 'contextState') || $this->markTestSkipped('needs phasync 2.0.0-beta5');
});

test('each application counts in its own state: overlapping requests never share an array', function () {
    with_app(function (string $addr) {
        $rounds = [];
        foreach ([1, 2, 3] as $round) {
            $rounds[$round] = \array_map(fn ($r) => \json_decode($r['body'], true), overlapping(\array_map(fn () => [new Browser($addr), '/state/count'], \range(1, 4))));
        }
        // Four at once need four applications
        expect(\array_unique(\array_column($rounds[1], 'app')))->toHaveCount(4);
        // An application serves one request at a time, so its counter goes up by one each time, and nobody else's does
        $served = [];
        foreach (\array_merge(...$rounds) as $r) {
            $served[$r['app']][] = $r['counter'];
            expect($r['after'])->toBe($r['counter']);
        }
        foreach ($served as $counters) {
            \sort($counters);
            expect($counters)->toBe(\range(1, \count($counters)));
        }
        // Four at a time, and a spare
        expect(\count($served))->toBeLessThanOrEqual(5);
    }, workers: 1, env: STATE);
});

test('what a request sets in the state is not seen by a request that overlaps it', function () {
    with_app(function (string $addr) {
        foreach ([1, 2] as $round) {
            $tags     = ['a', 'b', 'c', 'd', 'e'];
            $requests = \array_map(fn ($tag) => [new Browser($addr), "/state/bleed/$tag" . (1 === $round ? '' : '?wait=0.2')], $tags);
            $bodies   = \array_map(fn ($r) => \json_decode($r['body'], true)['x'], overlapping($requests));
            expect($bodies)->toBe($tags);
        }
    }, workers: 1, env: STATE);
});

test('a streamed response runs in the state of its request, also while the spare boots', function () {
    with_app(function (string $addr) {
        // The callback waits while the spare application boots in its own state, and echoes what the request set in its own.
        // One at a time: without phasync-ext, overlapping requests mix what they echo while they wait
        $browser = new Browser($addr);
        expect($browser->get('/state/stream/a')['body'])->toBe('a')
            ->and($browser->get('/state/stream/b')['body'])->toBe('b');
    }, workers: 1, env: STATE);
});

test('an idle application takes the slots of classes declared after its state was made', function () {
    with_app(function (string $addr) {
        $browser = new Browser($addr);
        $boots   = fn () => $browser->json('/boots')['boots'];
        // Two applications are idle once the first request has been answered: the one that served it, and the spare
        $browser->json('/state/count');
        expect(eventually(fn () => $boots() >= 2, true))->toBeTrue();
        expect($browser->json('/state/late?set=1'))->toBe(['late' => null]);
        // Both take it, one after the other and both at once
        expect($browser->json('/state/late'))->toBe(['late' => 'v']);
        $both = \array_map(fn ($r) => \json_decode($r['body'], true), overlapping(\array_map(fn () => [new Browser($addr), '/state/late?wait=0.2'], [1, 2])));
        expect($both)->toBe([['late' => 'v'], ['late' => 'v']]);
    }, workers: 1, env: STATE);
});

test('a WebSocket callback runs outside the state of the application that upgraded it; Handler::run() has a state of its own', function () {
    $log = with_app(function (string $addr) {
        $ws = ws_connect($addr, '/state/ws');
        foreach ([1, 2] as $i) {
            ws_send($ws, 'hi');
            [$opcode, $payload] = ws_read($ws);
            // The marker the request set is the application's, and Handler::run() starts every time from a new application's state
            expect(\json_decode($payload, true))->toBe(['callback' => null, 'run' => [null, 1]]);
        }
        \fclose($ws);
    }, workers: 1, env: STATE);
    expect($log)->not->toMatch('/error|exception/i');
});
