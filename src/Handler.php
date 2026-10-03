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
use phasync\Psr\ServerRequest;
use phasync\Util\Pool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Virtual;
use Swerve\Swerve;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A Laravel application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Laravel\Handler(__DIR__);
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
 * request's coroutine (Current). With phasync-ext's virtualize() each request's output buffers
 * are its own too, which Laravel's view engine needs when a view waits while it renders.
 *
 * Output a route prints outside its response (echo, dd()) is not supported without phasync-ext:
 * swerve ends the worker on it. With phasync-ext it is discarded, and the response stays the route's.
 *
 * When a request takes the last idle application, a spare is booted in the background, so that the
 * next request finds one ready.
 *
 * Each application has a phasync::$contextState array of its own, which the request that takes it
 * runs with. Whether the pointers to the application are proxied is decided once, when the first
 * application has booted: if classes have registered context-local state by then
 * (phasync::$contextStateDefaults is not empty) the pointers are context-local state too, each
 * application's own, and Current is not used; otherwise Current proxies them. A class that
 * registers context-local state after that, in an application that is served through Current,
 * makes every later request fail with a LogicException: the pointers would be shared.
 */
final class Handler implements RequestHandlerInterface
{
    /** The worker's handler, for run() */
    private static self $current;

    private readonly Client $client;

    /** @var Pool<Application> the booted applications: idle ones, and the ones requests are using */
    private readonly Pool $pool;

    /** @var \WeakMap<Application, array{array, array, array}> what each application's container held when it was booted */
    private \WeakMap $booted;

    /** Each request runs in phasync-ext's virtualize(): its output buffers are its own */
    private readonly bool $virtual;

    /** @var \WeakMap<Application, AppState> the context-local state of each application */
    private \WeakMap $states;

    /** The pointers to the application are proxied by {@see Current}, as no class registered context-local state when the first application booted */
    private readonly bool $proxied;

    /**
     * @param string $root            the application's root directory, where composer.json is
     * @param string $basePath        the folder the application is served under when a reverse proxy
     *                                strips it before swerve sees the request, such as '/demos/app';
     *                                url(), redirects and signed URLs then include it, as under PHP-FPM
     * @param float  $idleSeconds     how long an application may sit unused before it is dropped; a
     *                                worker boots as many as its overlapping requests need
     * @param int    $maxApplications the most applications a worker has at once, about 0.5 MiB each
     *                                while a request uses it; requests beyond that wait for one to
     *                                come free
     */
    public function __construct(private readonly string $root, private readonly string $basePath = '', float $idleSeconds = 60.0, int $maxApplications = 128)
    {
        // As under Octane: Laravel then hands a generator stream's callback over as it is, and the client yields its chunks
        $_SERVER['LARAVEL_OCTANE'] = 1;
        // The first application only learns the application's class and warms opcache
        $app = require "$root/bootstrap/app.php";
        $app->make(ConsoleKernel::class)->bootstrap();
        // Context-local state registered by now: the application's own pointers are per context, nothing is proxied
        $this->proxied = [] === \phasync::$contextStateDefaults;
        $this->proxied && Current::install($app);
        $this->virtual = Virtual::available();
        $this->client  = new Client($app->publicPath(), \rtrim($basePath, '/'), $this->virtual);
        $this->booted  = new \WeakMap();
        $this->states  = new \WeakMap();
        $this->pool    = new Pool($this->create(...), $maxApplications, $idleSeconds, static fn (Application $app) => $app->flush());
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
        $task    = static fn () => \phasync::await(\phasync::go(
            static fn () => $handler->withApplication(static fn () => $callback()),
            context: new \stdClass(), // its own, so that the application is its own
        ));

        return $task();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Reading and parsing the body happens here, before the application is made
        [$laravelRequest, $context] = $this->client->marshalRequest($request);
        $this->checkMode();
        $app = $this->take();
        if ($this->virtual) {
            // The response is what the application hands over, and the request goes on in virtualize()
            // after that; what it prints is discarded, as the response is not made of it
            return Virtual::run($context['psr'], function (\Closure $respond) use ($app, $laravelRequest, $context) {
                $context['respond'] = $respond;
                $this->serve($app, $laravelRequest, $context);
            }, handOver: true);
        }
        $serve = fn () => $this->serve($app, $laravelRequest, $context);
        if (isset($context['detached'])) {
            \phasync::go(function () use ($serve, $context) {
                try {
                    $serve();
                } finally {
                    $context['done'] = true;
                    \phasync::raiseFlag($context);
                }
            });
            while (!isset($context['handed']) && !isset($context['done'])) {
                \phasync::awaitFlag($context);
            }
        } else {
            $serve();
        }
        $response = $context['response'] ?? throw new \RuntimeException('The Laravel application ended without a response');
        // Only swerve holds a streamed body now: dropping it tells the producer the client left
        unset($context['response']);
        if (isset($context['detached'])) {
            $context['returned'] = true;
            \phasync::raiseFlag($context);
        }

        return $response;
    }

    /**
     * An application from the pool, which waits while all are in use. Taking the last idle one has
     * a spare made, so that the next request finds one ready.
     */
    private function take(): Application
    {
        $app = $this->pool->borrow();
        // Every application is lent: none idle, none being made (count() includes both), so one spare at a time
        if (\count($this->pool) === $this->pool->lent() && !Swerve::draining()) {
            $this->pool->warm(static fn (\Throwable $e) => Swerve::log()->error('A spare application did not boot: {exception}', ['exception' => $e]));
        }

        return $app;
    }

    /**
     * One request, in $app, an application of its own while it runs, reset by Octane's listeners
     * afterwards and given back to the pool. The response is handed over as soon as the kernel made
     * it; terminate() and the reset follow in the same coroutine, after swerve got the response.
     */
    private function serve(Application $app, Request $request, \ArrayObject $context): void
    {
        $this->states[$app]->adopt();
        $this->proxied && Current::set($app);
        // Nothing of this request is reused: the pool drops the application (and flushes it); the error is the response unless one was handed over
        $fail = function (\Throwable $e) use ($app, $context) {
            try {
                $this->client->error($context, $e, (bool) $app['config']->get('app.debug'));
                $app[ExceptionHandler::class]->report($e);
            } finally {
                $this->proxied && Current::unset();
                $this->pool->discard($app);
            }
        };
        try {
            $this->client->bind($app, $context);
            $kernel = $app->make(HttpKernel::class);
            $request->enableHttpMethodParameterOverride();
            $app['events']->dispatch(new RequestReceived($app, $app, $request));
            $response = $kernel->handle($request);
        } catch (\Throwable $e) {
            $fail($e);

            return;
        }
        $after = function () use ($app, $kernel, $request, $response) {
            $reset = false;
            try {
                $kernel->terminate($request, $response);
                $app['events']->dispatch(new RequestTerminated($app, $app, $request, $response));
                $request->route()?->flushController();
                // What the request resolved or registered goes, as when Octane drops its sandbox; the
                // objects the application booted with stay, and so does the state they reset themselves
                \Closure::bind(function (array $instances, array $rebound, array $terminating) {
                    $this->instances            = $instances;
                    $this->reboundCallbacks     = $rebound;
                    $this->terminatingCallbacks = $terminating;
                }, $app, Application::class)(...$this->booted[$app]);
                $reset = true;
            } catch (\Throwable $e) {
                $app[ExceptionHandler::class]->report($e);
            } finally {
                $this->proxied && Current::unset();
                if ($reset) {
                    // The context may live on (a WebSocket callback): with a copy of the state, not the array of an application another request takes now
                    $left = \phasync::$contextState;
                    \phasync::adoptContextState($left);
                    $this->pool->release($app);
                } else {
                    $this->pool->discard($app);
                }
            }
        };
        $streamed = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;
        if ($streamed && !$this->virtual && !isset($context['detached'])) {
            // The callback runs while swerve reads the body: a coroutine of its own
            $context['detached'] = true;
            \phasync::go(function () use ($context, $response, $after, $fail) {
                try {
                    $this->client->respond($context, $response);
                } catch (\Throwable $e) {
                    $fail($e);

                    return;
                } finally {
                    $context['done'] = true;
                    \phasync::raiseFlag($context);
                }
                $after();
            });

            return;
        }
        try {
            $this->client->respond($context, $response);
        } catch (\Throwable $e) {
            $fail($e);

            return;
        }
        // Without a coroutine of its own the request ends once swerve has sent the response
        isset($context['detached']) || $this->virtual ? $after() : \phasync::finally($after);
    }

    /**
     * A new application for the pool, booted as a request would be, in a virtualize() of its own when
     * phasync-ext has one, the response being the empty one of a run that echoed nothing.
     */
    private function create(): Application
    {
        $boot = function () use (&$app) {
            $app = $this->boot();
            $this->proxied && Current::unset();
        };
        $this->virtual ? Virtual::run(new ServerRequest('GET', '/', ''), $boot) : $boot();

        return $app;
    }

    /**
     * A new application, bootstrapped as the HTTP kernel does; it has no request yet.
     * It is booted in a state of its own, which the running context keeps.
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
        $this->client->prepare($app);
        // Octane's default does not delete uploads; PHP-FPM does
        $app['events']->listen(RequestTerminated::class, FlushUploadedFiles::class);
        foreach ($app['config']->get('octane.warm', []) as $service) {
            if (\is_string($service) && $app->bound($service)) {
                $app->make($service);
            }
        }
        $this->booted[$app] = \Closure::bind(fn () => [$this->instances, $this->reboundCallbacks, $this->terminatingCallbacks], $app, Application::class)();
        $state->sync(); // classes other applications declared while this one booted
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

    /** Context-local state registered after the first application booted cannot be given to pointers that are proxied already */
    private function checkMode(): void
    {
        if ($this->proxied && [] !== \phasync::$contextStateDefaults) {
            throw new \LogicException('Context-local state was registered (' . \implode(', ', \array_keys(\phasync::$contextStateDefaults)) . ') after the first application booted, when none was: the application is served with process-wide pointers. Register it before the first application has booted, such as in a service provider');
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
