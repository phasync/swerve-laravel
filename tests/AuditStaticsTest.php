<?php

/*
 * Static state in Laravel and its vendors that holds request state, probed by the /auds/{probe}/{tag}
 * routes of tests/Fixtures/routes/audit-statics.php. Request 1 sets the state and waits; request 2
 * starts meanwhile, and must not see request 1's state, nor request 1 its (changed) state;
 * request 3, after both, must not see what they left. The requests overlap with and without phasync-ext
 * (the routes wait with swerve_test_wait()).
 */

/** [browser, path, headers] each, started at once; the responses decoded. */
function auds_overlap(array $requests): array
{
    $multi   = \curl_multi_init();
    $handles = $responses = [];
    foreach ($requests as $i => [$browser, $path, $headers]) {
        $handles[$i] = \curl_init("http://{$browser->addr}$path");
        \curl_setopt_array($handles[$i], $browser->options('GET', [...$headers, 'Accept: application/json'], null, $responses[$i]));
        \curl_multi_add_handle($multi, $handles[$i]);
    }
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.05);
    } while ($running > 0);
    foreach ($handles as $i => $ch) {
        $body = \curl_multi_getcontent($ch);
        $out  = \json_decode($body, true);
        if (!\is_array($out) || 200 !== \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE)) {
            throw new RuntimeException('Request ' . ($i + 1) . ' failed with ' . \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE) . ': ' . \substr($body, 0, 400));
        }
        $responses[$i] = $out;
    }

    return $responses;
}

function auds_probe(string $probe): void
{
    $problems = [];
    $log      = with_app(function (string $addr) use ($probe, &$problems) {
        // Three applications, so none is booted while the probe runs
        auds_overlap(\array_map(fn () => [new Browser($addr), '/audr/request-attr/W?wait=0.3', []], [1, 2, 3]));
        [$one, $two] = auds_overlap([
            [new Browser($addr), "/auds/$probe/1?page=1&wait=0.8", ['Host: h1.test']],
            [new Browser($addr), "/auds/$probe/2?page=2&wait=0.1&delay=0.25", ['Host: h2.test']],
        ]);
        [$three] = auds_overlap([[new Browser($addr), "/auds/$probe/3?page=3", ['Host: h3.test']]]);

        if (!($two['start'] > $one['set'] && $two['end'] < $one['read'])) {
            throw new RuntimeException('The requests did not overlap: ' . \json_encode([$one, $two]));
        }
        $ambient = 'ambient' === $one['kind'];
        $mine    = fn (string $tag) => 'flag' === $one['kind'] ? 'set' : $tag;
        $none    = fn (string $tag) => $ambient ? $tag : 'none';
        $want    = [
            'request 2 before (while request 1 holds its state)'    => [$two['before'], $none('2')],
            'request 1 after (request 2 set its own meanwhile)'     => [$one['after'], $mine('1')],
            'request 2 after'                                       => [$two['after'], $mine('2')],
            'request 3 before (after requests 1 and 2 were done)'   => [$three['before'], $none('3')],
        ];
        foreach ($want as $what => [$got, $expected]) {
            $expected === $got || $problems[] = "$what: saw '$got', wanted '$expected'";
        }
    }, 1); // one worker, or the requests may not share statics
    expect($problems)->toBe([]);
    expect($log)->not->toMatch('/error|exception/i');
}

foreach ([
    'Facade::$resolvedInstance, Event::fake()'               => 'facade-event-fake',
    'Facade::$resolvedInstance, Mail::fake()'                => 'facade-mail-fake',
    'Facade::$resolvedInstance, Queue::fake()'               => 'facade-queue-fake',
    'Model::$dispatcher, Event::fake()'                      => 'model-dispatcher-fake',
    'Model::$dispatcher, Model::withoutEvents()'             => 'model-without-events',
    'Model::$unguarded, Model::unguard()'                    => 'model-unguard',
    'Model::$unguarded, Model::unguarded()'                  => 'model-unguarded',
    'Model::$ignoreOnTouch, Model::withoutTouching()'        => 'model-without-touching',
    'Model::$modelsShouldPreventLazyLoading'                 => 'model-prevent-lazy',
    'Relation::$constraints, Relation::noConstraints()'      => 'relation-constraints',
    'JsonResource::$wrap'                                    => 'json-resource-wrap',
    'AbstractPaginator::$defaultView'                        => 'paginator-default-view',
    'Password::$defaultCallback'                             => 'password-defaults',
    'Carbon test clock, Carbon::setTestNow()'                => 'carbon-test-now',
    'Carbon test clock, Carbon::withTestNow()'               => 'carbon-with-test-now',
    'DateFactory::$callable, Date::useCallable()'            => 'date-use-callable',
    'Carbon::setToStringFormat()'                            => 'carbon-to-string',
    'PHP default timezone, date_default_timezone_set()'      => 'default-timezone',
    'CarbonInterval locale after App::setLocale()'           => 'carbon-interval-locale',
    'Carbon diffForHumans() locale after App::setLocale()'   => 'carbon-diff-locale',
    'Number::$locale, Number::useLocale()'                   => 'number-locale',
    'Number::$locale, Number::withLocale()'                  => 'number-with-locale',
    'Str::$randomStringFactory'                              => 'str-random-factory',
    'Sleep::$fake, Sleep::fake()'                            => 'sleep-fake',
    'Queue::$createPayloadCallbacks'                         => 'queue-payload-hook',
    'MailManager mailer, Mail::alwaysTo()'                   => 'mail-always-to',
    'LogManager shared context, Log::shareContext()'         => 'log-shared-context',
    'Once::$instance, once()'                                => 'once',
    'Uri::$urlGeneratorResolver'                             => 'uri-resolver',
    'AbstractPaginator::$currentPageResolver'                => 'paginator-page',
    'AbstractPaginator::$currentPathResolver'                => 'paginator-path',
    'AbstractPaginator::$queryStringResolver'                => 'paginator-query',
    'View factory shared data, View::share()'                => 'view-share',
    'AbstractPaginator::$viewFactoryResolver'                => 'paginator-views',
    'Component::$factory'                                    => 'component-factory',
    'Gate user resolver'                                     => 'gate-user',
] as $label => $probe) {
    test("static state: $label", fn () => auds_probe($probe));
}
