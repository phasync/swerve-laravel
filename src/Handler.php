<?php

namespace Swerve\Laravel;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use phasync\Util\Pool;
use Swerve\ClientRequest;
use Swerve\Laravel\ExceptionHandler as SwerveExceptionHandler;
use Swerve\WorkerStoppingException;

/**
 * A Laravel application served by swerve: {@see serve()} makes one per worker, pooled so that
 * overlapping requests each borrow one of their own, and run in any mode through
 * {@see LaravelAdapter}. A `swerve.php` of your own may build one with other settings:
 *
 *     $handler = new Swerve\Laravel\Handler(__DIR__, maxApplications: 32);
 *     return Swerve\AsyncMode::serve($handler->publicPath(), $handler);
 *
 * Every request runs in an application of its own while it runs, taken from a phasync\Util\Pool of
 * booted applications, of which at most $maxApplications exist at once: a request that finds them all
 * in use waits for one, which is the worker's back-pressure. Laravel Octane's listeners reset it (RequestReceived), the HTTP kernel handles
 * the request and terminates it (terminate() and defer() callbacks, after the response went to
 * swerve), and the container goes back to what it held after boot. Requests share the worker's
 * classes and opcache, and PHP's static properties.
 *
 * Requests overlap in a worker whenever one waits in a coroutine. Laravel's process-wide pointers
 * to "the" application (app(), the facades, Eloquent's connection resolver, ...) follow the
 * request's Scope (Current). PHP's output buffers stay the process's: a view that waits while it
 * renders mixes its output with that of a request overlapping it there.
 *
 * Output a route prints outside its response (echo, dd()) is not part of the response.
 *
 * Each application has an AppState of its own, which the request that takes it runs with
 * (AppState::$state, swapped by the request's Scope). Whether the pointers to the application are
 * proxied is decided once, when the first application has booted: if classes have added slots to
 * AppState::$defaults by then, the pointers are per application already, and Current is not used;
 * otherwise Current proxies them. A slot added after that, in an application that is served
 * through Current, makes every later request fail with a LogicException: the pointers would be
 * shared.
 */
final class Handler
{
    /** The worker's handler, for run() */
    private static self $current;

    private readonly Client $client;

    /** @var Pool<Application> the booted applications: the ones requests are using, and idle ones kept for the window */
    private readonly Pool $pool;

    /** @var \WeakMap<Application, array<string, mixed>> each application's properties as they were when it was booted: its container and providers */
    private \WeakMap $booted;

    /** @var \WeakMap<Application, AppState> the state of each application */
    private \WeakMap $states;

    /** The pointers to the application are proxied by {@see Current}, as no class added a slot to AppState::$defaults when the first application booted */
    private readonly bool $proxied;

    /** The application's public directory, for {@see serve()} to pass as the mode's public root */
    private readonly string $publicPath;

    /**
     * @param string $root            the application's root directory, where composer.json is
     * @param string $basePath        the folder the application is served under when a reverse proxy
     *                                strips it before swerve sees the request, such as '/demos/app';
     *                                url(), redirects and signed URLs then include it, as under PHP-FPM
     * @param float  $window          seconds to look back for the most applications that were in use at
     *                                once: a worker keeps that many and drops the rest, so it holds
     *                                what a burst needed for that long
     * @param int    $maxApplications the most applications a worker has at once, about 0.5 MiB each
     *                                while a request uses it; requests beyond that wait for one to
     *                                come free
     */
    public function __construct(private readonly string $root, private readonly string $basePath = '', float $window = 60.0, int $maxApplications = 128)
    {
        // As under Octane: Laravel then hands a generator stream's callback over as it is, and the client yields its chunks
        $_SERVER['LARAVEL_OCTANE'] = 1;
        // Each request's coroutines run with the state of the application serving it
        \phasync::onScopeChange(AppState::enter(...));
        // The application's autoloader (the vendor directory swerve runs from): swerve's worker loads only its own dependencies
        require_once $GLOBALS['_composer_autoload_path'] ?? "$root/vendor/autoload.php";
        // The first application only learns the application's class and warms opcache
        $app = require "$root/bootstrap/app.php";
        $app->make(ConsoleKernel::class)->bootstrap();
        // Slots added by now: the application's own pointers are per application, nothing is proxied
        $this->proxied = [] === AppState::$defaults;
        $this->proxied && Current::install($app);
        $this->publicPath = $app->publicPath();
        $this->client     = new Client($this->publicPath, \rtrim($basePath, '/'));
        $this->booted     = new \WeakMap();
        $this->states     = new \WeakMap();
        $this->pool       = new Pool($this->create(...), $maxApplications, $window, static fn (Application $app) => $app->flush());
        $app->flush();
        self::$current = $this;
    }

    /**
     * Run Laravel code outside a request, such as in a WebSocket callback: in a fresh
     * application, bootstrapped as `php artisan` does, and dropped afterwards. Returns what
     * $callback returns or throws what it throws.
     *
     *     foreach ($ws as $message) {
     *         Handler::run(fn () => Message::create(['user_id' => $userId, 'text' => $message]));
     *     }
     *
     * The application has no request, so no session or user: take the user before
     * WebSocket::from(). Outside run() and a request, app(), the facades and Eloquent throw.
     */
    public static function run(\Closure $callback): mixed
    {
        $handler = self::$current;

        // A scope of its own, so that the application is its own
        return \phasync::await((new \Scope())->run(static fn () => \phasync::go(
            static fn () => $handler->withApplication(static fn () => $callback()),
        )));
    }

    /** The application's public directory: pass to {@see \Swerve\AsyncMode}'s public root. */
    public function publicPath(): string
    {
        return $this->publicPath;
    }

    /** Serve one exchange: {@see LaravelAdapter} calls this, in the request's own scope. */
    public function handle(ClientRequest $client): void
    {
        // Reading and parsing the body happens here, before the application is taken
        $request = $this->client->toLaravel($client);
        $this->checkMode();
        $this->serve($client, $this->pool->borrow(), $request);
    }

    /**
     * One request, in $app, an application of its own while it runs, reset by Octane's listeners
     * afterwards and given back to the pool. The response is written as soon as the kernel made
     * it; terminate() and the reset follow, after the client has it.
     */
    private function serve(ClientRequest $client, Application $app, Request $request): void
    {
        $this->states[$app]->adopt();
        $this->proxied && Current::set($app);
        // Nothing of this request is reused: the pool drops the application (and flushes it). The error
        // is the response unless one was begun, which is cut off instead, so the client sees it incomplete
        $fail = function (\Throwable $e) use ($client, $app) {
            try {
                if ($e instanceof WorkerStoppingException) {
                    throw $e; // for swerve to end the exchange
                }
                $client->headersSent() ? $client->close() : $this->client->error($client, $e, (bool) $app['config']->get('app.debug'));
                $app[ExceptionHandler::class]->report($e);
            } finally {
                $this->proxied && Current::unset();
                $this->pool->discard($app);
            }
        };
        try {
            $kernel = $app->make(HttpKernel::class);
            $request->enableHttpMethodParameterOverride();
            $app['events']->dispatch(new RequestReceived($app, $app, $request));
            $response = $kernel->handle($request);
            if ($response instanceof WebSocketResponse) {
                // The application goes back to the pool before the socket's handler runs
                $client->sendResponseHeaders(101, $response->headers->allPreserveCaseWithoutCookies());
                $client->flush();
            } else {
                $this->client->send($client, $request, $response);
            }
        } catch (\Throwable $e) {
            $fail($e);

            return;
        }
        $reset = false;
        try {
            $kernel->terminate($request, $response);
            $this->proxied && Current::componentFactory();
            $app['events']->dispatch(new RequestTerminated($app, $app, $request, $response));
            $request->route()?->flushController();
            // What the request resolved or registered goes, as when Octane drops its sandbox; the
            // objects the application booted with stay, and so does the state they reset themselves
            \Closure::bind(function (array $booted) {
                foreach ($booted as $name => $value) {
                    $this->$name = $value;
                }
            }, $app, Application::class)($this->booted[$app]);
            $reset = true;
        } catch (\Throwable $e) {
            $app[ExceptionHandler::class]->report($e);
        } finally {
            $this->proxied && Current::unset();
            if ($reset) {
                // The request's scope may live on (a WebSocket handler): with a copy of the state, not the array of an application another request takes now
                $this->states[$app]->leave();
                $this->pool->release($app);
            } else {
                $this->pool->discard($app);
            }
        }
        if ($response instanceof WebSocketResponse) {
            $response->run($client);
        }
    }

    /** A new application for the pool, booted as a request would be */
    private function create(): Application
    {
        $app = $this->boot();
        $this->proxied && Current::unset();

        return $app;
    }

    /**
     * A new application, bootstrapped as the HTTP kernel does; it has no request yet.
     * It is booted in a state of its own, which the current scope keeps.
     */
    private function boot(): Application
    {
        $state = new AppState();
        $state->adopt();
        $app = require "$this->root/bootstrap/app.php";
        $this->proxied && Current::set($app);
        $app->instance('request', Request::create('/'));
        $kernel = $app->make(HttpKernel::class);
        // HandleExceptions adds PHP error and exception handlers and a shutdown function, none
        // removable: the first application did that, and they go through Current
        $app->bootstrapWith(\array_values(\array_diff((new \ReflectionMethod($kernel, 'bootstrappers'))->invoke($kernel), [HandleExceptions::class])));
        $this->install($app);
        // Octane's default does not delete uploads; PHP-FPM does
        $app['events']->listen(RequestTerminated::class, FlushUploadedFiles::class);
        // A worker that stops ends its requests with a WorkerStoppingException: swerve answers it, not Laravel
        $app->extend(ExceptionHandler::class, static fn (ExceptionHandler $handler) => new SwerveExceptionHandler($handler));
        foreach ($app['config']->get('octane.warm', []) as $service) {
            if (\is_string($service) && $app->bound($service)) {
                $app->make($service);
            }
        }
        $this->booted[$app] = \Closure::bind(fn () => \get_object_vars($this), $app, Application::class)();
        $this->states[$app] = $state;

        return $app;
    }

    /**
     * After an application bootstrapped: through Current its process-wide pointers follow the request;
     * otherwise only HandleExceptions' is set, the rest being per application already.
     */
    private function install(Application $app): void
    {
        $this->proxied ? Current::install($app) : \Closure::bind(static fn () => HandleExceptions::$app = $app, null, HandleExceptions::class)();
    }

    /** Slots added after the first application booted cannot be given to pointers that are proxied already */
    private function checkMode(): void
    {
        if ($this->proxied && [] !== AppState::$defaults) {
            throw new \LogicException('Slots were added to AppState::$defaults (' . \implode(', ', \array_keys(AppState::$defaults)) . ') after the first application booted, when there were none: the application is served with process-wide pointers. Add them before the first application has booted, such as in a service provider');
        }
    }

    /**
     * Run $code with a new application, bootstrapped by the console kernel as `php artisan` does,
     * as this coroutine's Laravel application; then flush it.
     */
    private function withApplication(\Closure $code): mixed
    {
        $this->checkMode();
        (new AppState())->adopt();
        $app = require "$this->root/bootstrap/app.php";
        $this->proxied && Current::set($app);
        try {
            $kernel = $app->make(ConsoleKernel::class);
            $app->bootstrapWith(\array_values(\array_diff((new \ReflectionMethod($kernel, 'bootstrappers'))->invoke($kernel), [HandleExceptions::class])));
            $this->install($app);

            return $code($app);
        } finally {
            $app->flush();
            $this->proxied && Current::unset();
        }
    }
}
