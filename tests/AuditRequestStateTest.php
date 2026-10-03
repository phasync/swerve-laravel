<?php

/*
 * Adversarial request-state leak tests: request A sets one piece of state and waits, request B
 * (another browser, another session) starts meanwhile, sets its own and finishes first, and a
 * sequential request C comes after. Each probe runs against a fresh worker (workers: 1), so one
 * leak cannot colour another. The routes are tests/Fixtures/routes/audit-request.php. Overlap needs
 * phasync-ext: SWERVE_PHP_ARGS="-d extension=.../phasync.so".
 */

require_once __DIR__ . '/Audit/overlap.php';

/** Per probe: login (browsers log in as their tag), own (reads own identity, no set), after(tag, base), base(tag), distinct, regen, kept. */
function audr_cases(): array
{
    $ab    = fn ($a, $b) => fn ($t) => 'A' === $t ? $a : $b;
    $list  = fn ($t) => [$t];
    $same  = fn ($t, $base) => $base;
    $cases = [];
    foreach (['request-attr', 'request-merge', 'session-global', 'globals', 'server-global', 'get-global', 'post-global', 'cookie-global', 'env-put', 'env-global', 'view-share', 'translator-lines', 'translator-fallback', 'log-context', 'context-add', 'view-errors', 'session-put', 'session-flash', 'session-old'] as $p) {
        $cases[$p] = [];
    }
    $cases['request-macro']        = ['after' => $list];
    $cases['response-macro']       = ['after' => $list];
    $cases['validator-extend']     = ['after' => $list];
    $cases['auth-shoulduse']       = ['after' => fn ($t) => "adv-$t"];
    $cases['auth-viarequest']      = ['after' => $list];
    $cases['gate-define']          = ['after' => $list];
    $cases['view-composer']        = ['after' => $list];
    $cases['exception-dontreport'] = ['after' => $list];
    $cases['cookie-queue']         = ['after' => fn ($t) => ["adv_$t"]];
    $cases['trusted-proxies']      = ['after' => $list];
    $cases['trusted-hosts']        = ['after' => fn ($t) => ['{^127\.0\.0\.1$}i', '{^' . \strtolower($t) . '\.example$}i']];
    $cases['url-defaults']         = ['after' => fn ($t) => ['adv' => $t]];
    $cases['url-root']             = ['after' => fn ($t) => 'http://' . \strtolower($t) . '.example/x'];
    $cases['url-setrequest']       = ['after' => fn ($t) => \strtolower($t) . '.example'];
    $cases['url-scheme']           = ['after' => $ab('https', 'http')];
    $cases['locale']               = ['after' => $ab('nb', 'de')];
    $cases['fallback-locale']      = ['after' => $ab('nb', 'de')];
    $cases['carbon-locale']        = ['after' => $ab('nb', 'de')];
    $cases['number-locale']        = ['after' => $ab('nb', 'de')];
    $cases['config-timezone']      = ['after' => $ab('Europe/Oslo', 'Asia/Tokyo')];
    $cases['php-timezone']         = ['after' => $ab('Europe/Oslo', 'Asia/Tokyo')];
    $cases['carbon-testnow']       = ['after' => $ab('2001', '2002')];
    $cases['ini-set']              = ['after' => $ab('5', '9')];
    $cases['setlocale']            = ['after' => $ab('en_US.utf8', 'C.utf8')];
    $cases['gate-before']          = ['after' => fn ($t) => 'A' === $t];
    $cases['route-name']           = ['after' => fn ($t) => "adv.$t"];
    $cases['session-errors']       = ['after' => fn () => true];
    $cases['ob-buffer']            = ['after' => fn ($t) => "ob-$t"];
    $cases['blade-stack']          = ['after' => $same];
    $cases['blade-once']           = ['after' => $same];
    $cases['route-param']          = ['own' => true];
    $cases['auth-user']            = ['own' => true, 'login' => true];
    $cases['auth-setuser']         = ['login' => true, 'base' => fn ($t) => $t];
    $cases['auth-guard']           = ['login' => true, 'base' => fn ($t) => $t];
    $cases['session-id']           = ['distinct' => true, 'after' => $same];
    $cases['csrf-token']           = ['distinct' => true, 'after' => $same];
    $cases['session-regenerate']   = ['distinct' => true, 'regen' => true];
    $cases['session-put']          = ['kept' => true];
    $cases['session-flash']        = ['kept' => true];
    $cases['session-old']          = ['kept' => true];
    foreach (['container-singleton', 'container-instance', 'container-resolving', 'container-extend', 'container-contextual', 'config-key', 'array-cache', 'context-hidden'] as $p) {
        $cases[$p] = [];
    }
    foreach (['container-alias', 'container-tag', 'container-provider', 'model-global-scope', 'rate-limiter', 'cache-extend'] as $p) {
        $cases[$p] = ['after' => $list];
    }
    $cases['vite-nonce']           = ['after' => fn ($t) => "n$t"];
    $cases['url-previous']         = ['after' => fn ($t) => "http://prev-$t.test/"];
    $cases['url-intended']         = ['after' => fn ($t) => "http://int-$t.test/"];
    $cases['password-defaults']    = ['after' => fn ($t) => 'A' === $t ? 20 : 30];
    $cases['file-rule-defaults']   = ['after' => fn ($t) => ["audz$t"]];
    $cases['queue-before']         = ['after' => fn ($t, $base) => $base + 1];
    $cases['bus-pipe']             = ['after' => fn ($t) => ["AudzPipe$t"]];
    $cases['str-uuid']             = ['after' => fn ($t) => 'A' === $t ? 'a' : 'b'];
    $cases['number-currency']      = ['after' => fn ($t) => 'A' === $t ? 'EUR' : 'JPY'];
    $cases['sleep-fake']           = ['after' => fn () => true];
    $cases['url-key-resolver']     = ['after' => fn ($t) => "key-$t"];
    $cases['session-errors'] += ['kept' => true];

    return \array_map(fn ($o) => $o + ['after' => fn ($t) => $t], $cases);
}

beforeEach(function () {
    $this->adv = fn (Closure $test) => with_app($test, workers: 1);
});

foreach (audr_cases() as $probe => $opt) {
    // Probed state: $probe
    test("request state '$probe' stays with its own request", function () use ($probe, $opt) {
        ($this->adv)(function (string $addr) use ($probe, $opt) {
            $login = $opt['login'] ?? false;
            $make  = function (string $tag) use ($addr, $login) {
                $b = new Browser($addr);
                $login && $b->get("/login-as/$tag");

                return $b;
            };
            $first = fn (Browser $b, string $tag, string $query) => $b->json("/audr/$probe/$tag$query");
            $own   = $opt['own'] ?? false;
            $base0 = $first($make('Z'), 'Z', '?wait=0')['before'];
            $base  = fn (string $t) => $own ? $t : (isset($opt['base']) ? $opt['base']($t) : $base0);
            $after = fn (string $t) => $own ? $t : $opt['after']($t, $base($t));

            // Overlapping: A sets and waits; B starts meanwhile, sets its own and finishes first
            [$ba, $bb] = [$make('A'), $make('B')];
            [$a, $b]   = audr_overlap([[$ba, "/audr/$probe/A?mode=set&wait=0.8", 0], [$bb, "/audr/$probe/B?mode=set&wait=0.1", 0.3]]);
            expect($a['status'] . $b['status'])->toBe('200200', \substr(\json_encode([$a, $b]), 0, 600));
            audr_nested($a, $b);
            if ($opt['distinct'] ?? false) {
                $c = $first($make('C'), 'C', '?wait=0');
                expect(\count(\array_unique([$a['before'], $b['before'], $c['before']])))->toBe(3, 'per-request values shared: ' . \json_encode([$a, $b, $c]));
                if ($opt['regen'] ?? false) {
                    expect($a['after'])->not->toBe($a['before'])->and($b['before'])->not->toBe($a['after']);
                }

                return;
            }
            // Every deviation is collected, so a failure says which of overlapping (concurrent) and later (sequential) requests saw what
            $problems = [];
            $check    = function (string $what, mixed $got, mixed $want) use (&$problems) {
                $got === $want || $problems[] = $what . ': saw ' . \json_encode($got) . ', wanted ' . \json_encode($want);
            };
            $check('concurrent: B before setting its own', $b['before'], $base('B'));
            $check('concurrent: B after setting', $b['after'], $after('B'));
            $check('concurrent: A after waiting (B set its own meanwhile)', $a['after'], $after('A'));
            $check('concurrent: A before', $a['before'], $base('A'));

            // Sequential: new requests after both are done start from the baseline
            foreach (['C', 'D', 'E'] as $tag) {
                $check("sequential: $tag after A and B", $first($make($tag), $tag, '?wait=0')['before'], $base($tag));
            }
            // What a session kept is its own
            if ($opt['kept'] ?? false) {
                $check("kept: A's session", $first($ba, 'A', '?wait=0')['before'], $after('A'));
                $check("kept: B's session", $first($bb, 'B', '?wait=0')['before'], $after('B'));
            }
            expect($problems)->toBe([]);
        });
    });
}

// Request input: $_GET, $_POST, $_COOKIE, $_SERVER, php://input of each request, read around a wait
test('superglobals and php://input stay with their own request', function () {
    with_app(function (string $addr) {
        $mk  = fn (string $t) => (function () use ($addr, $t) {
            $b                 = new Browser($addr);
            $b->cookies['adv'] = $t;

            return $b;
        })();
        $req     = fn (string $t, float $delay, float $wait) => [$mk($t), "/audr-in/$t?q=q$t&wait=$wait", $delay, 'POST', "p=p$t", ["X-Adv: h$t", 'Content-Type: application/x-www-form-urlencoded']];
        [$a, $b] = audr_overlap([$req('A', 0, 0.8), $req('B', 0.3, 0.1)]);
        audr_nested($a, $b);
        $want = fn (string $t) => ['get' => "q$t", 'post' => "p$t", 'cookie' => $t, 'header' => "h$t", 'uri' => $t, 'input' => "p=p$t", 'req' => ["q$t", "p$t", "h$t", "p=p$t"]];
        expect([$a['before'], $a['after'], $b['before'], $b['after']])->toBe([$want('A'), $want('A'), $want('B'), $want('B')]);
    }, workers: 1);
});

// Raw header() and http_response_code(): a plain request must not inherit another's
test('header() and http_response_code() stay with their own request', function () {
    with_app(function (string $addr) {
        [$a, $b] = audr_overlap([[new Browser($addr), '/audr-raw/A?mode=set&wait=0.8', 0], [new Browser($addr), '/audr-raw/B?wait=0.1', 0.3]]);
        audr_nested($a, $b);
        expect($b['code'])->not->toBe(299, 'B inherited http_response_code() of A')
            ->and(\implode(',', $b['headers']))->not->toContain('X-Adv', 'B inherited header() of A');
        $c = (new Browser($addr))->get('/audr-raw/C?wait=0');
        expect($c['status'])->toBe(200)
            ->and($c['headers'])->not->toHaveKey('x-adv', 'C inherited header() of A');
    }, workers: 1);
});

// mt_srand(): A seeds and waits; B reseeds and draws meanwhile; A's draw must still be seed 42's first
test('mt_srand() sequence stays with its own request', function () {
    with_app(function (string $addr) {
        \mt_srand(42);
        $expected = \mt_rand();
        [$a, $b]  = audr_overlap([[new Browser($addr), '/audr-rand/A?seed=42&wait=0.8', 0], [new Browser($addr), '/audr-rand/B?seed=7&wait=0.1', 0.3]]);
        audr_nested($a, $b);
        expect($a['draw'])->toBe($expected, "A's mt_rand() sequence was disturbed by B");
    }, workers: 1);
});
