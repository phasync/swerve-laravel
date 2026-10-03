<?php

/*
 * An application that registers context-local state (APP_CONTEXT_STATE makes the fixture register a
 * slot in phasync::$contextStateDefaults): each pooled application has a phasync::$contextState
 * array of its own, and a request runs with the one of the application that serves it. The fixture
 * application keeps nothing in static properties that are context-local state, so these tests look
 * at the arrays themselves, through routes that use phasync::$contextState as an application would.
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
        // Four at a time, no spare
        expect(\count($served))->toBe(4);
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

test('a streamed response runs in the state of its request, also while other applications boot', function () {
    with_app(function (string $addr) {
        // The callback waits while the other requests' applications boot in their own state, and echoes what the request set in its own.
        // The others do not stream: without phasync-ext, overlapping streams mix what they echo while they wait
        foreach (['a', 'b'] as $tag) {
            $responses = overlapping([[new Browser($addr), "/state/stream/$tag"], [new Browser($addr), '/state/count'], [new Browser($addr), '/state/count']]);
            expect($responses[0]['body'])->toBe($tag);
        }
    }, workers: 1, env: STATE);
});

test('an idle application takes the slots of classes declared after its state was made', function () {
    with_app(function (string $addr) {
        $browser = new Browser($addr);
        $boots   = fn () => $browser->json('/boots')['boots'];
        // Two applications are idle once two requests that overlapped have been answered
        overlapping(\array_map(fn () => [new Browser($addr), '/state/count'], [1, 2]));
        expect($boots())->toBeGreaterThanOrEqual(2);
        expect($browser->json('/state/late?set=1'))->toBe(['late' => null]);
        // Both take it, one after the other and both at once
        expect($browser->json('/state/late'))->toBe(['late' => 'v']);
        $both = \array_map(fn ($r) => \json_decode($r['body'], true), overlapping(\array_map(fn () => [new Browser($addr), '/state/late?wait=0.2'], [1, 2])));
        expect($both)->toBe([['late' => 'v'], ['late' => 'v']]);
    }, workers: 1, env: STATE);
});

test('a WebSocket callback keeps the state its request left it, whatever the application serves meanwhile; Handler::run() has a state of its own', function () {
    $log = with_app(function (string $addr) {
        $ws = ws_connect($addr, '/state/ws');
        foreach ([1, 2] as $i) {
            ws_send($ws, 'hi');
            [$opcode, $payload] = ws_read($ws);
            // The marker the request set is the callback's too, and Handler::run() starts every time from a new application's state
            expect(\json_decode($payload, true))->toBe(['callback' => 'request', 'run' => [null, 1]]);
        }
        \fclose($ws);
    }, workers: 1, env: STATE);
    expect($log)->not->toMatch('/error|exception/i');
});

test('a WebSocket callback reads back what it set at its start, while other requests use the pool', function () {
    $log = with_app(function (string $addr) {
        $ids   = \range(1, 6);
        $conns = [];
        foreach ($ids as $id) {
            $conns[$id] = ws_connect($addr, "/state/ws-keep/$id");
            expect((new Browser($addr))->json("/state/bleed/other$id")['x'])->toBe("other$id");
        }
        foreach ($conns as $conn) {
            ws_send($conn, 'hi');
        }
        // Requests that write their own values while the callbacks wait
        $meanwhile = overlapping(\array_map(fn ($i) => [new Browser($addr), "/state/bleed/m$i?wait=0.2"], \range(1, 4)));
        expect(\array_map(fn ($r) => \json_decode($r['body'], true)['x'], $meanwhile))->toBe(['m1', 'm2', 'm3', 'm4']);
        foreach ($conns as $id => $conn) {
            expect(ws_read($conn))->toBe([1, (string) $id]);
            \fclose($conn);
        }
    }, workers: 1, env: STATE);
    expect($log)->not->toMatch('/error|exception/i');
});

test('the same handler proxies the process-wide pointers for a plain application, and leaves them to an application with context-local state', function () {
    $modes = [];
    foreach (['plain' => [], 'context-local' => STATE] as $name => $env) {
        with_app(function (string $addr) use ($name, &$modes) {
            $modes[$name] = (new Browser($addr))->json('/state/mode');
        }, workers: 1, env: $env);
    }
    expect($modes)->toBe([
        'plain'         => ['proxy' => true, 'registered' => false],
        'context-local' => ['proxy' => false, 'registered' => true],
    ]);
});

test('a plain application that registers context-local state after it booted fails its requests, rather than run with the wrong state', function () {
    with_app(function (string $addr) {
        $browser = new Browser($addr);
        expect($browser->get('/state/count')['status'])->toBe(200);
        // The class declaration that registers a slot comes too late for the pointers, which were set up as process-wide
        expect($browser->get('/state/late?set=1')['status'])->toBe(200);
        expect($browser->get('/state/count')['status'])->toBe(500);
    }, workers: 1);
});
