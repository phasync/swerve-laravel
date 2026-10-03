<?php

// Routes of tests/AuditStaticsTest.php, added to the Laravel skeleton's routes/web.php by tests/create-app.sh.
// /auds/{probe}/{tag}: read the probed static ("before"), set it to the tag, wait ?wait=, read it again
// ("after"). ?delay= waits before the first read. A probe's kind says what it reads:
//   tag     'none' until set, then the tag
//   flag    'none' until set, then 'set'
//   ambient the tag always: derived from this request, the tag being in its URL, ?page= and Host

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Support\Uri;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\Component;

if (!\class_exists('AdvComponent')) {
    class AdvComponent extends Component
    {
        public function render()
        {
            return '';
        }
    }
}

Gate::define('adv-who', fn ($user) => Response::allow((string) $user->getAuthIdentifier()));

if (!\function_exists('auds_probes')) {
    /** @return array<string, array{0: Closure, 1: Closure, 2: string}> probe => [set($tag, $inner), read($tag), kind] */
    function auds_probes(): array
    {
        $digit   = static fn (mixed $v): string => \is_string($v) && 1 === \strlen($v) && \ctype_digit($v) ? $v : 'none';
        $locales = ['1' => 'nb', '2' => 'de', '3' => 'fr'];
        $static  = static fn (string $class, string $name) => \Closure::bind(static fn () => static::$$name, null, $class)();
        // A set that stays until something unsets it, and one that holds while $inner runs
        $persist = static fn (Closure $set) => static function (string $tag, Closure $inner) use ($set) {
            $set($tag);
            $inner();
        };
        $none = static function (string $tag, Closure $inner) {
            $inner();
        };
        $toBe = static fn (bool $on): string => $on ? 'set' : 'none';

        return [
            // Facade::$resolvedInstance, set by Facade::swap() which every fake() calls
            'facade-event-fake'      => [$persist(fn () => Event::fake()), fn () => $toBe(Event::getFacadeRoot() instanceof EventFake), 'flag'],
            'facade-mail-fake'       => [$persist(fn () => Mail::fake()), fn () => $toBe(Mail::getFacadeRoot() instanceof MailFake), 'flag'],
            'facade-queue-fake'      => [$persist(fn () => Queue::fake()), fn () => $toBe(Queue::getFacadeRoot() instanceof QueueFake), 'flag'],
            // Model::$dispatcher, replaced by Event::fake()
            'model-dispatcher-fake'  => [$persist(fn () => Event::fake()), fn () => $toBe(Model::getEventDispatcher() instanceof EventFake), 'flag'],
            // Model::$dispatcher, swapped for a NullDispatcher while the callback runs
            'model-without-events'   => [fn ($t, $inner) => Model::withoutEvents($inner), fn () => $toBe(Model::getEventDispatcher() instanceof Illuminate\Events\NullDispatcher), 'flag'],
            // GuardsAttributes::$unguarded
            'model-unguard'          => [$persist(fn () => Model::unguard()), fn () => $toBe(Model::isUnguarded()), 'flag'],
            'model-unguarded'        => [fn ($t, $inner) => Model::unguarded($inner), fn () => $toBe(Model::isUnguarded()), 'flag'],
            // Model::$ignoreOnTouch
            'model-without-touching' => [fn ($t, $inner) => \App\Models\User::withoutTouching($inner), fn () => $toBe(\App\Models\User::isIgnoringTouch()), 'flag'],
            // Model::$modelsShouldPreventLazyLoading
            'model-prevent-lazy'     => [$persist(fn () => Model::preventLazyLoading()), fn () => $toBe(Model::preventsLazyLoading()), 'flag'],
            // Relation::$constraints
            'relation-constraints'   => [fn ($t, $inner) => Relation::noConstraints($inner), fn () => $toBe(false === $static(Relation::class, 'constraints')), 'flag'],
            // JsonResource::$wrap
            'json-resource-wrap'     => [$persist(fn ($t) => JsonResource::wrap($t)), fn () => $digit(JsonResource::$wrap), 'tag'],
            // AbstractPaginator::$defaultView
            'paginator-default-view' => [$persist(fn ($t) => Paginator::defaultView("v$t")), fn () => $digit(\substr(AbstractPaginator::$defaultView, 1)), 'tag'],
            // Password::$defaultCallback
            'password-defaults'      => [$persist(fn ($t) => Password::defaults(fn () => Password::min(10 + (int) $t))), fn () => $digit((string) ((fn () => $this->min)->call(Password::default()) - 10)), 'tag'],
            // Carbon's test clock
            'carbon-test-now'        => [$persist(fn ($t) => Carbon::setTestNow(Carbon::createFromTimestampUTC(1000 + (int) $t))), fn () => Carbon::hasTestNow() ? (string) (Carbon::now()->timestamp - 1000) : 'none', 'tag'],
            'carbon-with-test-now'   => [fn ($t, $inner) => Carbon::withTestNow(Carbon::createFromTimestampUTC(1000 + (int) $t), $inner), fn () => Carbon::hasTestNow() ? 'set' : 'none', 'flag'],
            // DateFactory::$callable
            'date-use-callable'      => [$persist(fn ($t) => Date::useCallable(fn ($d) => $d->setTimestamp(1000 + (int) $t))), fn () => $digit((string) (Date::now()->timestamp - 1000)), 'tag'],
            // Carbon's static toString format
            'carbon-to-string'       => [$persist(fn ($t) => Carbon::setToStringFormat('\\' . $t)), fn () => $digit((string) Carbon::now()), 'tag'],
            // The PHP default timezone, which Carbon and date() follow
            'default-timezone'       => [
                $persist(fn ($t) => \date_default_timezone_set(['1' => 'Asia/Tokyo', '2' => 'America/New_York', '3' => 'Europe/Oslo'][$t])),
                fn () => (string) (\array_search(\date_default_timezone_get(), ['1' => 'Asia/Tokyo', '2' => 'America/New_York', '3' => 'Europe/Oslo'], true) ?: 'none'),
                'tag',
            ],
            // Carbon's locale in the classes other than Carbon, after App::setLocale()
            'carbon-interval-locale' => [$persist(fn ($t) => App::setLocale($locales[$t])), fn () => (string) (\array_search(CarbonInterval::days(2)->forHumans(), ['1' => '2 dager', '2' => '2 Tage', '3' => '2 jours'], true) ?: 'none'), 'tag'],
            'carbon-diff-locale'     => [$persist(fn ($t) => App::setLocale($locales[$t])), fn () => (string) (\array_search(Carbon::now()->subDays(2)->diffForHumans(), ['1' => '2 dager siden', '2' => 'vor 2 Tagen', '3' => 'il y a 2 jours'], true) ?: 'none'), 'tag'],
            // Number::$locale
            'number-locale'          => [$persist(fn ($t) => Number::useLocale($locales[$t])), fn () => (string) (\array_search(Number::spell(2), ['1' => 'to', '2' => 'zwei', '3' => 'deux'], true) ?: 'none'), 'tag'],
            'number-with-locale'     => [fn ($t, $inner) => Number::withLocale($locales[$t], $inner), fn () => $toBe('two' !== Number::spell(2)), 'flag'],
            // Str::$randomStringFactory
            'str-random-factory'     => [$persist(fn ($t) => Str::createRandomStringsUsing(fn ($n) => \str_repeat($t, $n))), fn () => \preg_match('/^(\d)\1{7}$/', Str::random(8), $m) ? $m[1] : 'none', 'tag'],
            // Sleep::$fake
            'sleep-fake'             => [$persist(fn () => Sleep::fake()), function () use ($toBe) {
                $start = \microtime(true);
                Sleep::for(20)->milliseconds();

                return $toBe(\microtime(true) - $start < 0.01);
            }, 'flag'],
            // Queue::$createPayloadCallbacks
            'queue-payload-hook'     => [
                $persist(fn ($t) => Illuminate\Queue\Queue::createPayloadUsing(fn () => ['adv' => $t])),
                fn () => $digit((fn () => $this->createPayloadArray('Job', 'default', ['x'])['adv'] ?? null)->call(Queue::connection('sync'))),
                'tag',
            ],
            // Per-application singletons: the mailer's address and the log's shared context
            'mail-always-to'         => [$persist(fn ($t) => Mail::alwaysTo("t$t@adv.test")), fn () => $digit(\substr((string) (fn () => $this->to['address'] ?? '')->call(Mail::mailer()), 1, 1)), 'tag'],
            'log-shared-context'     => [$persist(fn ($t) => Log::shareContext(['adv' => $t])), fn () => $digit(Log::sharedContext()['adv'] ?? null), 'tag'],
            // Once::$instance: memoises a value read from the request, with no variable of it in its hash
            'once'                   => [$none, fn () => (string) once(fn () => request()->query('page')), 'ambient'],
            // Uri::$urlGeneratorResolver
            'uri-resolver'           => [$none, fn () => \preg_match('/^h(\d)\.test$/', Uri::to('/')->host(), $m) ? $m[1] : (string) Uri::to('/')->host(), 'ambient'],
            // AbstractPaginator's resolvers
            'paginator-page'         => [$none, fn () => (string) Paginator::resolveCurrentPage(), 'ambient'],
            'paginator-path'         => [$none, fn () => \basename(Paginator::resolveCurrentPath()), 'ambient'],
            'paginator-query'        => [$none, fn () => (string) (Paginator::resolveQueryString()['page'] ?? 'none'), 'ambient'],
            // The view factory's shared data, the control for the next two
            'view-share'             => [$persist(fn ($t) => View::share('adv', $t)), fn () => $digit(View::shared('adv')), 'tag'],
            // AbstractPaginator::$viewFactoryResolver, with a marker in this request's view factory
            'paginator-views'        => [$persist(fn ($t) => View::share('adv', $t)), fn () => $digit(Paginator::viewFactory()->shared('adv')), 'tag'],
            // Component::$factory, the view factory a component caches on first use
            'component-factory'      => [$persist(fn ($t) => View::share('adv', $t)), fn () => $digit((fn () => $this->factory()->shared('adv'))->call(new AdvComponent())), 'tag'],
            // Gate, whose user comes from the request's guard
            'gate-user'              => [$persist(fn ($t) => Auth::setUser(new GenericUser(['id' => $t]))), fn () => $digit(Gate::inspect('adv-who')->message()), 'tag'],
        ];
    }
}

Route::get('/auds/{probe}/{tag}', function (Request $request, string $probe, string $tag) {
    [$set, $read, $kind] = auds_probes()[$probe];
    swerve_test_wait((float) $request->query('delay', 0));
    $out = ['kind' => $kind, 'start' => \microtime(true), 'before' => $read($tag)];
    $set($tag, function () use ($request, $read, $tag, &$out) {
        $out['set'] = \microtime(true);
        swerve_test_wait((float) $request->query('wait', 0));
        $out['read']  = \microtime(true);
        $out['after'] = $read($tag);
    });
    $out['end'] = \microtime(true);

    return $out;
})->withoutMiddleware('web');
