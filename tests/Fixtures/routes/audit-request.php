<?php

// Adversarial request-state probes (tests/AuditRequestStateTest.php), added by tests/create-app.sh.
// /audr/{probe}/{tag}: read the state, (mode=set) set it to {tag}, wait, read again; the response
// carries both reads and the server-side start and end times.

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;

if (!\function_exists('audr_probes')) {
    class AdvA extends Exception
    {
    }

    class AdvB extends Exception
    {
    }

    class AudzCtx
    {
        public function __construct(public string $x = 'none')
        {
        }
    }

    class AudzProvA extends ServiceProvider
    {
    }

    class AudzProvB extends ServiceProvider
    {
    }

    class AudzModel extends Illuminate\Database\Eloquent\Model
    {
    }

    /** probe => [set($tag, $request), read($request), cleanup($request)?] */
    function audr_probes(): array
    {
        $rules = fn () => \array_values(\array_filter(['A', 'B'], function ($t) {
            try {
                Validator::make(['x' => 1], ['x' => 'adv_' . \strtolower($t)])->passes();

                return true;
            } catch (BadMethodCallException) {
                return false;
            }
        }));
        // The application's handler, under Collision's wrapper when it is installed
        $handler = fn () => (fn () => $this->appExceptionHandler ?? $this)->call(app(ExceptionHandler::class));
        $macros  = fn ($class) => \array_values(\array_filter(['A', 'B'], fn ($t) => $class::hasMacro("adv$t")));

        return [
            // request
            'request-attr'    => [fn ($t, $r) => $r->attributes->set('adv', $t), fn ($r) => $r->attributes->get('adv')],
            'request-merge'   => [fn ($t) => request()->merge(['adv' => $t]), fn () => request()->input('adv')],
            'request-macro'   => [fn ($t) => Request::macro("adv$t", fn () => $t), fn () => $macros(Request::class)],
            'response-macro'  => [fn ($t) => Illuminate\Support\Facades\Response::macro("adv$t", fn () => $t), fn () => $macros(Illuminate\Routing\ResponseFactory::class)],
            'route-param'     => [fn ($t) => null, fn () => Route::current()?->parameter('tag')],
            'route-name'      => [fn ($t) => Route::current()->name("adv.$t"), fn () => Route::current()->getName()],
            'trusted-proxies' => [fn ($t) => Request::setTrustedProxies([$t], Request::HEADER_X_FORWARDED_FOR), fn () => Request::getTrustedProxies()],
            'trusted-hosts'   => [fn ($t) => Request::setTrustedHosts(['^127\.0\.0\.1$', '^' . \strtolower($t) . '\.example$']), fn () => Request::getTrustedHosts()],
            // session
            'session-put'        => [fn ($t, $r) => $r->session()->put('adv', $t), fn ($r) => $r->session()->get('adv')],
            'session-flash'      => [fn ($t, $r) => $r->session()->flash('adv', $t), fn ($r) => $r->session()->get('adv')],
            'session-old'        => [fn ($t, $r) => $r->session()->flashInput(['adv' => $t]), fn ($r) => $r->session()->getOldInput('adv')],
            'session-errors'     => [fn ($t, $r) => $r->session()->flash('errors', new Illuminate\Support\ViewErrorBag()), fn ($r) => $r->session()->has('errors')],
            'session-id'         => [fn ($t, $r) => null, fn ($r) => $r->session()->getId()],
            'csrf-token'         => [fn ($t) => null, fn () => csrf_token()],
            'session-regenerate' => [fn ($t, $r) => $r->session()->regenerate(), fn ($r) => $r->session()->getId()],
            // cookies
            'cookie-queue'  => [fn ($t) => Cookie::queue("adv_$t", $t), fn () => \array_values(\array_map(fn ($c) => $c->getName(), Cookie::getQueuedCookies()))],
            // auth
            'auth-user'      => [fn ($t) => null, fn () => Auth::user()?->name],
            'auth-setuser'   => [fn ($t) => Auth::setUser(new GenericUser(['id' => 0, 'name' => $t])), fn () => Auth::user()?->name],
            'auth-shoulduse' => [function ($t) {
                config(['auth.guards.adv-' . $t => config('auth.guards.web')]);
                Auth::shouldUse("adv-$t");
            }, fn () => Auth::getDefaultDriver()],
            'auth-guard'      => [fn ($t) => Auth::guard('web')->setUser(new GenericUser(['id' => 0, 'name' => $t])), fn () => Auth::guard('web')->user()?->name],
            'auth-viarequest' => [fn ($t) => Auth::viaRequest("adv$t", fn () => null), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => isset((new ReflectionProperty(Auth::getFacadeRoot(), 'customCreators'))->getValue(Auth::getFacadeRoot())["adv$t"])))],
            'gate-define'     => [fn ($t) => Gate::define("adv-$t", fn () => true), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => Gate::has("adv-$t")))],
            'gate-before'     => [fn ($t) => Gate::before(fn (?Illuminate\Contracts\Auth\Authenticatable $u = null) => 'A' === $t), fn () => Gate::allows('adv-none', [], true)],
            // locale, time
            'locale'          => [fn ($t) => App::setLocale('A' === $t ? 'nb' : 'de'), fn () => App::getLocale()],
            'fallback-locale' => [fn ($t) => App::setFallbackLocale('A' === $t ? 'nb' : 'de'), fn () => App::getFallbackLocale()],
            'carbon-locale'   => [fn ($t) => Carbon::setLocale('A' === $t ? 'nb' : 'de'), fn () => Carbon::getLocale()],
            'number-locale'   => [fn ($t) => Number::useLocale('A' === $t ? 'nb' : 'de'), fn () => Number::defaultLocale()],
            'config-timezone' => [fn ($t) => config(['app.timezone' => 'A' === $t ? 'Europe/Oslo' : 'Asia/Tokyo']), fn () => config('app.timezone')],
            'php-timezone'    => [fn ($t) => \date_default_timezone_set('A' === $t ? 'Europe/Oslo' : 'Asia/Tokyo'), fn () => \date_default_timezone_get()],
            'carbon-testnow'  => [fn ($t) => Carbon::setTestNow('A' === $t ? '2001-01-01' : '2002-01-01'), fn () => Carbon::hasTestNow() ? Carbon::now()->format('Y') : null],
            // url
            'url-root'       => [fn ($t) => URL::forceRootUrl('http://' . \strtolower($t) . '.example'), fn () => url('/x')],
            'url-scheme'     => [fn ($t) => URL::forceScheme('a' === \strtolower($t) ? 'https' : 'http'), fn () => \parse_url(url('/x'), \PHP_URL_SCHEME)],
            'url-defaults'   => [fn ($t) => URL::defaults(['adv' => $t]), fn () => URL::getDefaultParameters()],
            'url-setrequest' => [fn ($t) => URL::setRequest(Request::create('http://' . \strtolower($t) . '.example/p')), fn () => \parse_url(url()->current(), \PHP_URL_HOST)],
            // views
            'view-share'    => [fn ($t) => View::share('adv', $t), fn () => View::shared('adv')],
            'view-composer' => [fn ($t) => View::composer("adv.$t", fn () => null), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => [] !== View::getFacadeRoot()->getContainer()['events']->getListeners("composing: adv.$t")))],
            'blade-stack'   => [fn ($t) => null, fn () => \trim(Blade::render('@push("s")ADV@endpush<x>@stack("s")</x>'))],
            'blade-once'    => [fn ($t) => null, fn () => \trim(Blade::render('@once ONCE @endonce'))],
            'view-errors'   => [fn ($t) => View::share('errors', (new Illuminate\Support\ViewErrorBag())->put('default', new Illuminate\Support\MessageBag(['adv' => $t]))), fn () => View::shared('errors')?->first('adv')],
            // validation, translation
            'validator-extend'    => [fn ($t) => Validator::extend('adv_' . \strtolower($t), fn () => true), $rules],
            'translator-lines'    => [fn ($t) => app('translator')->addLines(['adv.k' => $t], 'en'), fn () => __('adv.k')],
            'translator-fallback' => [fn ($t) => app('translator')->setFallback($t), fn () => app('translator')->getFallback()],
            // logging, context
            'log-context'   => [fn ($t) => Log::shareContext(['adv' => $t]), fn () => Log::sharedContext()['adv'] ?? null],
            'context-add'   => [fn ($t) => Context::add('adv', $t), fn () => Context::get('adv')],
            // exceptions
            'exception-dontreport' => [fn ($t) => $handler()->ignore('Adv' . $t), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => !$handler()->shouldReport(new ('Adv' . $t))))],
            // PHP globals
            'server-global'  => [fn ($t) => $_SERVER['ADV'] = $t, fn () => $_SERVER['ADV'] ?? null],
            'get-global'     => [fn ($t) => $_GET['adv'] = $t, fn () => $_GET['adv'] ?? null],
            'post-global'    => [fn ($t) => $_POST['adv'] = $t, fn () => $_POST['adv'] ?? null],
            'cookie-global'  => [fn ($t) => $_COOKIE['adv'] = $t, fn () => $_COOKIE['adv'] ?? null],
            'session-global' => [fn ($t) => $_SESSION['adv'] = $t, fn () => $_SESSION['adv'] ?? null],
            'globals'        => [fn ($t) => $GLOBALS['adv'] = $t, fn () => $GLOBALS['adv'] ?? null],
            'env-put'        => [fn ($t) => \putenv("ADV=$t"), fn () => \getenv('ADV') ?: null],
            'env-global'     => [fn ($t) => $_ENV['ADV'] = $t, fn () => $_ENV['ADV'] ?? null],
            'ini-set'        => [fn ($t) => \ini_set('precision', 'A' === $t ? '5' : '9'), fn () => \ini_get('precision')],
            'setlocale'      => [fn ($t) => \setlocale(\LC_NUMERIC, 'A' === $t ? 'en_US.utf8' : 'C.utf8'), fn () => \setlocale(\LC_NUMERIC, '0')],
            // application container: what a request registers on the (pooled) application
            'container-singleton'  => [fn ($t) => app()->singleton('audz.tenant', fn () => $t), fn () => app()->bound('audz.tenant') ? app('audz.tenant') : null],
            'container-instance'   => [fn ($t) => app()->instance('audz.inst', $t), fn () => app()->bound('audz.inst') ? app('audz.inst') : null],
            'container-alias'      => [fn ($t) => app()->alias('request', "audz.alias.$t"), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => app()->isAlias("audz.alias.$t")))],
            'container-tag'        => [fn ($t) => app()->tag([stdClass::class], "audz-tag-$t"), fn () => \array_values(\array_filter(['A', 'B'], function ($t) {
                try {
                    return [] !== \iterator_to_array(app()->tagged("audz-tag-$t"), false);
                } catch (Throwable) {
                    return false;
                }
            }))],
            'container-resolving'  => [fn ($t) => app()->resolving(stdClass::class, fn ($o) => $o->audz = $t), fn () => app()->make(stdClass::class)->audz ?? null],
            'container-extend'     => [fn ($t) => app()->extend(ArrayObject::class, fn ($o) => tap($o, fn ($o) => $o['audz'] = $t)), fn () => app()->make(ArrayObject::class)['audz'] ?? null],
            'container-contextual' => [fn ($t) => app()->when(AudzCtx::class)->needs('$x')->give($t), fn () => app()->make(AudzCtx::class)->x],
            'container-provider'   => [fn ($t) => app()->register('A' === $t ? AudzProvA::class : AudzProvB::class), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => null !== app()->getProvider('A' === $t ? AudzProvA::class : AudzProvB::class)))],
            'config-key'           => [fn ($t) => config(['audz.key' => $t]), fn () => config('audz.key')],
            // static state of framework classes
            'vite-nonce'           => [fn ($t) => Vite::useCspNonce("n$t"), fn () => Vite::cspNonce()],
            'model-global-scope'   => [fn ($t) => AudzModel::addGlobalScope("audz$t", fn ($q) => $q), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => AudzModel::hasGlobalScope("audz$t")))],
            'password-defaults'    => [fn ($t) => Password::defaults(fn () => Password::min('A' === $t ? 20 : 30)), fn () => (fn () => $this->min)->call(Password::default())],
            'file-rule-defaults'   => [fn ($t) => File::defaults(fn () => File::types(["audz$t"])), fn () => (fn () => $this->allowedMimetypes)->call(File::default())],
            'rate-limiter'         => [fn ($t) => RateLimiter::for("audz-$t", fn () => Illuminate\Cache\RateLimiting\Limit::none()), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => null !== RateLimiter::limiter("audz-$t")))],
            'array-cache'          => [fn ($t) => Cache::store('array')->put('audz', $t), fn () => Cache::store('array')->get('audz')],
            'cache-extend'         => [fn ($t) => Cache::extend("audz_$t", fn () => Cache::repository(new Illuminate\Cache\ArrayStore())), fn () => \array_values(\array_filter(['A', 'B'], fn ($t) => \array_key_exists("audz_$t", (fn () => $this->customCreators)->call(Cache::getFacadeRoot()))))],
            'queue-before'         => [fn ($t) => Queue::before(fn () => null), fn () => \count(app('events')->getListeners(Illuminate\Queue\Events\JobProcessing::class))],
            'bus-pipe'             => [fn ($t) => Bus::pipeThrough(["AudzPipe$t"]), fn () => (fn () => $this->pipes)->call(Bus::getFacadeRoot())],
            'str-uuid'             => [fn ($t) => Str::createUuidsUsing(fn () => Ramsey\Uuid\Uuid::fromString('00000000-0000-4000-8000-00000000000' . ('A' === $t ? 'a' : 'b'))), function () { $u = (string) Str::uuid(); return \str_starts_with($u, '00000000-0000-4000-8000-00000000000') ? \substr($u, -1) : 'none'; }],
            'number-currency'      => [fn ($t) => Number::useCurrency('A' === $t ? 'EUR' : 'JPY'), fn () => Number::defaultCurrency()],
            'sleep-fake'           => [fn ($t) => Illuminate\Support\Sleep::fake(), fn () => (new ReflectionProperty(Illuminate\Support\Sleep::class, 'fake'))->getValue()],
            'context-hidden'       => [fn ($t) => Context::addHidden('audz', $t), fn () => Context::getHidden('audz')],
            // session-bound url state
            'url-previous'         => [fn ($t, $r) => $r->session()->setPreviousUrl("http://prev-$t.test/"), fn ($r) => $r->session()->previousUrl()],
            'url-intended'         => [fn ($t) => redirect()->setIntendedUrl("http://int-$t.test/"), fn () => session('url.intended')],
            'url-key-resolver'     => [fn ($t) => URL::setKeyResolver(fn () => "key-$t"), fn () => (fn () => ($this->keyResolver)())->call(URL::getFacadeRoot())],
            'ob-buffer'      => [function ($t) {
                \ob_start();
                echo "ob-$t";
            }, fn () => \ob_get_contents(), fn () => \ob_end_clean()],
        ];
    }
}

Route::get('/audr/{probe}/{tag}', function (Request $request, string $probe, string $tag) {
    [$set, $read] = audr_probes()[$probe];
    $out          = ['tag' => $tag, 'start' => \microtime(true), 'before' => $read($request)];
    if ('set' === $request->query('mode')) {
        $set($tag, $request);
    }
    swerve_test_wait((float) $request->query('wait', 0.3));
    $out['after'] = $read($request);
    'set' === $request->query('mode') && isset(audr_probes()[$probe][2]) && audr_probes()[$probe][2]();
    $out['end']   = \microtime(true);

    return $out;
});

// What the request itself carries, read before and after a wait: superglobals, php://input
Route::match(['GET', 'POST'], '/audr-in/{tag}', function (Request $request, string $tag) {
    $read = fn () => [
        'get'    => $_GET['q'] ?? null,
        'post'   => $_POST['p'] ?? null,
        'cookie' => $_COOKIE['adv'] ?? null,
        'header' => $_SERVER['HTTP_X_ADV'] ?? null,
        'uri'    => \basename(\parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH)),
        'input'  => \file_get_contents('php://input'),
        'req'    => [$request->query('q'), $request->input('p'), $request->header('X-Adv'), $request->getContent()],
    ];
    $out = ['start' => \microtime(true), 'before' => $read()];
    swerve_test_wait((float) $request->query('wait', 0.3));
    $out['after'] = $read();
    $out['end']   = \microtime(true);

    return $out;
})->withoutMiddleware(\array_filter([Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, 'Illuminate\Foundation\Http\Middleware\PreventRequestForgery'], 'class_exists'));

// Seed mt_rand(), wait, draw: the draw is the seed's first number if nobody else seeded or drew meanwhile
Route::get('/audr-rand/{tag}', function (Request $request, string $tag) {
    $start = \microtime(true);
    \mt_srand($request->integer('seed'));
    swerve_test_wait((float) $request->query('wait', 0.3));

    return ['start' => $start, 'draw' => \mt_rand(), 'end' => \microtime(true)];
});

// Raw PHP response state: header() and http_response_code() set by one request, a plain request meanwhile
Route::get('/audr-raw/{tag}', function (Request $request, string $tag) {
    if ('set' === $request->query('mode')) {
        \header("X-Adv: $tag");
        \http_response_code(299);
    }
    $start = \microtime(true);
    swerve_test_wait((float) $request->query('wait', 0.3));

    return response(\json_encode(['start' => $start, 'end' => \microtime(true), 'code' => \http_response_code(), 'headers' => \headers_list()]));
});
