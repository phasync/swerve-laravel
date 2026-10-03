<?php

// Routes of tests/IsolationTest.php, added to the Laravel skeleton's routes/web.php by tests/create-app.sh.
// Each probe reads some state a request can change, which no other request may see: the routes read it,
// set it, wait, and read it again, while another request overlaps or follows.

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Support\Uri;
use Illuminate\View\Component;

// MariaDB (the test container on :13306) for queries that really wait; the tests skip when it is not there
config(['database.connections.isomysql' => [
    'driver'   => 'mysql', 'host' => \getenv('ISO_MYSQL_HOST') ?: '127.0.0.1', 'port' => \getenv('ISO_MYSQL_PORT') ?: 13306,
    'database' => 'adv_resources', 'username' => 'root', 'password' => 'test', 'charset' => 'utf8mb4',
]]);

if (!\function_exists('iso_probes')) {
    class IsoCtx
    {
        public function __construct(public string $x = 'none')
        {
        }
    }

    class IsoProvA extends ServiceProvider
    {
    }

    class IsoProvB extends ServiceProvider
    {
    }

    class IsoComponent extends Component
    {
        public function render()
        {
            return '';
        }
    }

    /** probe => [set($tag, $request), read($request)] */
    function iso_probes(): array
    {
        $both = fn (Closure $has) => \array_values(\array_filter(['A', 'B'], $has));

        return [
            // request, session, cookies
            'route-param'        => [fn ($t) => null, fn () => Route::current()?->parameter('tag')],
            'session-put'        => [fn ($t, $r) => $r->session()->put('iso', $t), fn ($r) => $r->session()->get('iso')],
            'session-flash'      => [fn ($t, $r) => $r->session()->flash('iso', $t), fn ($r) => $r->session()->get('iso')],
            'session-old'        => [fn ($t, $r) => $r->session()->flashInput(['iso' => $t]), fn ($r) => $r->session()->getOldInput('iso')],
            'session-id'         => [fn ($t, $r) => null, fn ($r) => $r->session()->getId()],
            'session-regenerate' => [fn ($t, $r) => $r->session()->regenerate(), fn ($r) => $r->session()->getId()],
            'csrf-token'         => [fn ($t) => null, fn () => csrf_token()],
            'cookie-queue'       => [fn ($t) => Cookie::queue("iso_$t", $t), fn () => \array_values(\array_map(fn ($c) => $c->getName(), Cookie::getQueuedCookies()))],
            // auth
            'auth-user'          => [fn ($t) => null, fn () => Auth::user()?->name],
            'auth-setuser'       => [fn ($t) => Auth::setUser(new GenericUser(['id' => 0, 'name' => $t])), fn () => Auth::user()?->name],
            'auth-guard'         => [fn ($t) => Auth::guard('web')->setUser(new GenericUser(['id' => 0, 'name' => $t])), fn () => Auth::guard('web')->user()?->name],
            'auth-shoulduse'     => [function ($t) {
                config(['auth.guards.iso-' . $t => config('auth.guards.web')]);
                Auth::shouldUse("iso-$t");
            }, fn () => Auth::getDefaultDriver()],
            // locale, config
            'locale'             => [fn ($t) => App::setLocale('A' === $t ? 'nb' : 'de'), fn () => App::getLocale()],
            'fallback-locale'    => [fn ($t) => App::setFallbackLocale('A' === $t ? 'nb' : 'de'), fn () => App::getFallbackLocale()],
            'carbon-locale'      => [fn ($t) => Carbon::setLocale('A' === $t ? 'nb' : 'de'), fn () => Carbon::getLocale()],
            'config-key'         => [fn ($t) => config(['iso.key' => $t]), fn () => config('iso.key')],
            'config-timezone'    => [fn ($t) => config(['app.timezone' => 'A' === $t ? 'Europe/Oslo' : 'Asia/Tokyo']), fn () => config('app.timezone')],
            // url
            'url-root'           => [fn ($t) => URL::forceRootUrl('http://' . \strtolower($t) . '.example'), fn () => url('/x')],
            'url-scheme'         => [fn ($t) => URL::forceScheme('A' === $t ? 'https' : 'http'), fn () => \parse_url(url('/x'), \PHP_URL_SCHEME)],
            'url-defaults'       => [fn ($t) => URL::defaults(['iso' => $t]), fn () => URL::getDefaultParameters()],
            'url-setrequest'     => [fn ($t) => URL::setRequest(Request::create('http://' . \strtolower($t) . '.example/p')), fn () => \parse_url(url()->current(), \PHP_URL_HOST)],
            'url-previous'       => [fn ($t, $r) => $r->session()->setPreviousUrl("http://prev-$t.test/"), fn ($r) => $r->session()->previousUrl()],
            'url-intended'       => [fn ($t) => redirect()->setIntendedUrl("http://int-$t.test/"), fn () => session('url.intended')],
            // what a request registers in the container
            'container-instance'   => [fn ($t) => app()->instance('iso.inst', $t), fn () => app()->bound('iso.inst') ? app('iso.inst') : null],
            'container-singleton'  => [fn ($t) => app()->singleton('iso.tenant', fn () => $t), fn () => app()->bound('iso.tenant') ? app('iso.tenant') : null],
            'container-resolving'  => [fn ($t) => app()->resolving(stdClass::class, fn ($o) => $o->iso = $t), fn () => app()->make(stdClass::class)->iso ?? null],
            'container-extend'     => [fn ($t) => app()->extend(ArrayObject::class, fn ($o) => tap($o, fn ($o) => $o['iso'] = $t)), fn () => app()->make(ArrayObject::class)['iso'] ?? null],
            'container-contextual' => [fn ($t) => app()->when(IsoCtx::class)->needs('$x')->give($t), fn () => app()->make(IsoCtx::class)->x],
            'container-alias'      => [fn ($t) => app()->alias('request', "iso.alias.$t"), fn () => $both(fn ($t) => app()->isAlias("iso.alias.$t"))],
            'container-tag'        => [fn ($t) => app()->tag([stdClass::class], "iso-tag-$t"), fn () => $both(function ($t) {
                try {
                    return [] !== \iterator_to_array(app()->tagged("iso-tag-$t"), false);
                } catch (Throwable) {
                    return false;
                }
            })],
            'container-provider'   => [fn ($t) => app()->register('A' === $t ? IsoProvA::class : IsoProvB::class), fn () => $both(fn ($t) => null !== app()->getProvider('A' === $t ? IsoProvA::class : IsoProvB::class))],
        ];
    }
}

// /iso/{probe}/{tag}: read the state, (?mode=set) set it to {tag}, wait, read again; the response
// carries both reads and the server-side start and end times
Route::get('/iso/{probe}/{tag}', function (Request $request, string $probe, string $tag) {
    [$set, $read] = iso_probes()[$probe];
    $out          = ['tag' => $tag, 'start' => \microtime(true), 'before' => $read($request)];
    'set' === $request->query('mode') && $set($tag, $request);
    swerve_test_wait((float) $request->query('wait', 0.3));
    $out['after'] = $read($request);
    $out['end']   = \microtime(true);

    return $out;
});

if (!\function_exists('iso_static_probes')) {
    /**
     * Process-wide state, probed from three requests of different tags and hosts. probe => [set($tag, $inner), read($tag), kind]
     * The kind says what a read returns: 'tag' is 'none' until set, then the tag; 'flag' 'none' until set, then 'set';
     * 'ambient' the tag always, derived from the request (the tag is in its URL, ?page= and Host).
     */
    function iso_static_probes(): array
    {
        $digit   = static fn (mixed $v): string => \is_string($v) && 1 === \strlen($v) && \ctype_digit($v) ? $v : 'none';
        $locales = ['1' => 'nb', '2' => 'de', '3' => 'fr'];
        $persist = static fn (Closure $set) => static function (string $tag, Closure $inner) use ($set) {
            $set($tag);
            $inner();
        };
        $none = static function (string $tag, Closure $inner) {
            $inner();
        };
        $toBe = static fn (bool $on): string => $on ? 'set' : 'none';

        return [
            // Facade::$resolvedInstance, set by Facade::swap(), which every fake() calls
            'facade-event-fake'      => [$persist(fn () => Event::fake()), fn () => $toBe(Event::getFacadeRoot() instanceof EventFake), 'flag'],
            'facade-mail-fake'       => [$persist(fn () => Mail::fake()), fn () => $toBe(Mail::getFacadeRoot() instanceof MailFake), 'flag'],
            'facade-queue-fake'      => [$persist(fn () => Queue::fake()), fn () => $toBe(Queue::getFacadeRoot() instanceof QueueFake), 'flag'],
            // The paginator's resolvers, which read the request and the view factory of the application (the marker is an
            // instance in the request's container, as the view factory's shared data outlives the request on Octane too)
            'paginator-page'         => [$none, fn () => (string) Paginator::resolveCurrentPage(), 'ambient'],
            'paginator-path'         => [$none, fn () => \basename(Paginator::resolveCurrentPath()), 'ambient'],
            'paginator-query'        => [$none, fn () => (string) (Paginator::resolveQueryString()['page'] ?? 'none'), 'ambient'],
            'paginator-views'        => [$persist(fn ($t) => app()->instance('iso.tag', $t)), fn () => $digit(Paginator::viewFactory()->getContainer()['iso.tag'] ?? null), 'tag'],
            // The view factory a component caches on first use
            'component-factory'      => [$persist(fn ($t) => app()->instance('iso.tag', $t)), fn () => $digit((fn () => $this->factory()->getContainer()['iso.tag'] ?? null)->call(new IsoComponent())), 'tag'],
            // Resolvers that read the application's request
            'uri-resolver'           => [$none, fn () => \preg_match('/^h(\d)\.test$/', Uri::to('/')->host(), $m) ? $m[1] : (string) Uri::to('/')->host(), 'ambient'],
            'gate-user'              => [$persist(fn ($t) => Auth::setUser(new GenericUser(['id' => $t]))), fn () => $digit(Gate::inspect('iso-who')->message()), 'tag'],
            // Singletons of the application
            'mail-always-to'         => [$persist(fn ($t) => Mail::alwaysTo("t$t@iso.test")), fn () => $digit(\substr((string) (fn () => $this->to['address'] ?? '')->call(Mail::mailer()), 1, 1)), 'tag'],
            'log-shared-context'     => [$persist(fn ($t) => Log::shareContext(['iso' => $t])), fn () => $digit(Log::sharedContext()['iso'] ?? null), 'tag'],
            // Carbon's locale in the classes other than Carbon, after App::setLocale()
            'carbon-interval-locale' => [$persist(fn ($t) => App::setLocale($locales[$t])), fn () => (string) (\array_search(CarbonInterval::days(2)->forHumans(), ['1' => '2 dager', '2' => '2 Tage', '3' => '2 jours'], true) ?: 'none'), 'tag'],
            'carbon-diff-locale'     => [$persist(fn ($t) => App::setLocale($locales[$t])), fn () => (string) (\array_search(Carbon::now()->subDays(2)->diffForHumans(), ['1' => '2 dager siden', '2' => 'vor 2 Tagen', '3' => 'il y a 2 jours'], true) ?: 'none'), 'tag'],
        ];
    }
}

Gate::define('iso-who', fn ($user) => Response::allow((string) $user->getAuthIdentifier()));

// /iso-static/{probe}/{tag}: read the state ("before"), set it to the tag, wait, read it again ("after").
// ?delay= waits before the first read
Route::get('/iso-static/{probe}/{tag}', function (Request $request, string $probe, string $tag) {
    [$set, $read, $kind] = iso_static_probes()[$probe];
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

if (!\class_exists('IsoTerm')) {
    /** Remembers the request in handle(); terminate() reports what it sees to storage/iso-term.log */
    class IsoTerm
    {
        public function handle(Request $request, Closure $next)
        {
            return $next($request)->header('X-Iso', $request->route('tag'));
        }

        public function terminate(Request $request, $response): void
        {
            \file_put_contents(storage_path('iso-term.log'), \json_encode([
                'url'     => $request->route('tag'),
                'header'  => $response->headers->get('X-Iso'),
                'current' => Route::current()?->parameter('tag'),
            ]) . "\n", \FILE_APPEND | \LOCK_EX);
        }
    }
}

Route::get('/iso-term/{tag}', function (Request $request, string $tag) {
    $start = \microtime(true);
    swerve_test_wait((float) $request->query('wait', 0.3));

    return ['tag' => $tag, 'start' => $start, 'end' => \microtime(true)];
})->middleware(IsoTerm::class);

// Explicit route-model binding that waits while resolving: the route's parameters are set, then another request runs
Route::bind('isobound', function (string $value) {
    swerve_test_wait('A' === $value ? 0.8 : 0.1);

    return (object) ['v' => $value];
});
Route::get('/iso-bind/{isobound}', function (Request $request, object $isobound) {
    $start = \microtime(true);
    swerve_test_wait('A' === $isobound->v ? 0.3 : 0.05);

    return ['start' => $start, 'param' => $isobound->v, 'route' => $request->route('isobound')->v, 'current' => Route::current()->parameter('isobound')->v, 'path' => $request->path(), 'end' => \microtime(true)];
});

// Validation that fails after a wait: each request gets its own message
Route::get('/iso-validate/{tag}', function (Request $request, string $tag) {
    $start  = \microtime(true);
    $errors = validator(['x' => $tag], ['x' => [function ($attribute, $value, $fail) {
        swerve_test_wait('A' === $value ? 0.8 : 0.1);
        $fail("bad-$value");
    }]])->errors();

    return ['start' => $start, 'message' => $errors->first('x'), 'bag' => $errors->all(), 'end' => \microtime(true)];
});

// A cookie and a header put on the response of one request, a wait meanwhile
Route::get('/iso-cookie/{tag}', function (Request $request, string $tag) {
    $start = \microtime(true);
    swerve_test_wait((float) $request->query('wait', 0.3));

    return response()->json(['start' => $start, 'end' => \microtime(true)])->cookie("iso_$tag", $tag)->header("X-Iso-$tag", $tag);
});

// Who the request is, before and after a wait
Route::get('/iso-who', function (Request $request) {
    $start  = \microtime(true);
    $before = [Auth::check(), Auth::user()?->name, $request->user()?->name];
    swerve_test_wait((float) $request->query('wait', 0.3));

    return ['start' => $start, 'before' => $before, 'after' => [Auth::check(), Auth::user()?->name, $request->user()?->name, auth()->guard()->user()?->name], 'end' => \microtime(true)];
});

// Two queries that really wait (SELECT SLEEP): connection, transaction, session variable, temp table and query log of the request
Route::get('/iso-db/{tag}', function (string $tag) {
    $db = DB::connection('isomysql');
    $db->statement('set @iso = ?', [$tag]);
    $db->statement('create temporary table if not exists iso_tmp (v varchar(20))');
    $db->table('iso_tmp')->insert(['v' => $tag]);
    $db->enableQueryLog();
    $db->beginTransaction();
    $start = \microtime(true);
    $db->select('select sleep(0.3)');
    $out = [
        'start' => $start, 'end' => \microtime(true),
        'level' => $db->transactionLevel(),
        'var'   => $db->selectOne('select @iso as v')->v,
        'tmp'   => $db->table('iso_tmp')->pluck('v')->all(),
        'log'   => \count($db->getQueryLog()),
        'conn'  => $db->selectOne('select connection_id() as id')->id,
    ];
    $db->rollBack();

    return $out;
});
