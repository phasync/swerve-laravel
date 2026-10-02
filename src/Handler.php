<?php

namespace Swerve\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Virtual;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Swerve\Swerve;

/**
 * A Laravel application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Laravel\Handler(__DIR__);
 *
 * Every request runs in an application of its own while it runs, taken from a pool of booted
 * applications. Laravel Octane's listeners reset it (RequestReceived), the HTTP kernel handles
 * the request and terminates it (terminate() and defer() callbacks, after the response went to
 * swerve), and the container goes back to what it held after boot. Requests share the worker's
 * classes and opcache, and PHP's static properties.
 *
 * Requests overlap in a worker whenever one waits in a coroutine. Laravel's process-wide pointers
 * to "the" application (app(), the facades, Eloquent's connection resolver, ...) follow the
 * request's coroutine (Current). With phasync-ext's virtualize() each request's output buffers
 * are its own too, which Laravel's view engine needs when a view waits while it renders.
 */
final class Handler implements RequestHandlerInterface
{
    /** The worker's handler, for run() */
    private static self $current;

    private readonly Client $client;

    /** @var list<Application> booted applications between requests, the longest idle first */
    private array $idle = [];

    /** @var \WeakMap<Application, float> when each idle application was last used */
    private \WeakMap $usedAt;

    private bool $sweeping = false;

    /** @var \WeakMap<Application, array{array, array}> what each application's container held when it was booted */
    private \WeakMap $booted;

    /** Each request runs in phasync-ext's virtualize(): its output buffers are its own */
    private readonly bool $virtual;

    /**
     * @param string $root        the application's root directory, where composer.json is
     * @param string $basePath    the folder the application is served under when a reverse proxy
     *                            strips it before swerve sees the request, such as '/demos/app';
     *                            url(), redirects and signed URLs then include it, as under PHP-FPM
     * @param float  $idleSeconds how long an application may sit unused before it is dropped; a
     *                            worker boots as many as its overlapping requests need
     */
    public function __construct(private readonly string $root, private readonly string $basePath = '', private readonly float $idleSeconds = 60.0)
    {
        // The first application only learns the application's class and warms opcache
        $app = require "$root/bootstrap/app.php";
        $app->make(ConsoleKernel::class)->bootstrap();
        Current::install($app);
        $this->client  = new Client($app->publicPath(), \rtrim($basePath, '/'));
        $this->virtual = Virtual::available();
        $this->booted  = new \WeakMap();
        $this->usedAt  = new \WeakMap();
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
        $this->sweeping || $this->startSweeping();
        $serve = fn () => $this->serve($laravelRequest, $context);
        if (isset($context['detached'])) {
            \phasync::go(function () use ($serve, $context) {
                try {
                    $this->virtual ? Virtual::run($context['psr'], $serve) : $serve();
                } finally {
                    $context['done'] = true;
                    \phasync::raiseFlag($context);
                }
            });
        } elseif ($this->virtual) {
            $context['virtual'] = true; // handing over the response releases Virtual::run(), the rest goes on in it
            Virtual::run($context['psr'], $serve);
        } else {
            $serve();
        }
        while (isset($context['detached']) && !isset($context['handed']) && !isset($context['done'])) {
            \phasync::awaitFlag($context);
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
     * One request, in an application of its own while it runs: from the pool, reset by Octane's
     * listeners afterwards. The response is handed over as soon as the kernel made it; terminate()
     * and the reset follow in the same coroutine, after swerve got the response.
     */
    private function serve(Request $request, \ArrayObject $context): void
    {
        $app = \array_pop($this->idle) ?? $this->boot();
        Current::set($app);
        // Nothing of this request is reused: the application goes; the error is the response unless one was handed over
        $fail = function (\Throwable $e) use ($app, $context) {
            $this->client->error($context, $e, (bool) $app['config']->get('app.debug'));
            $app[ExceptionHandler::class]->report($e);
            Current::unset();
            $app->flush();
        };
        try {
            $this->client->bind($app, $context);
            $kernel = $app->make(HttpKernel::class);
            $request->enableHttpMethodParameterOverride();
            $app['events']->dispatch(new RequestReceived($app, $app, $request));
            \ob_start();
            $response = $kernel->handle($request);
            $output   = \ob_get_contents();
            if (\ob_get_level()) {
                \ob_end_clean();
            }
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
                \Closure::bind(function (array $instances, array $rebound) {
                    $this->instances        = $instances;
                    $this->reboundCallbacks = $rebound;
                }, $app, Container::class)(...$this->booted[$app]);
                $reset = true;
            } catch (\Throwable $e) {
                $app[ExceptionHandler::class]->report($e);
            } finally {
                Current::unset();
                if ($reset) {
                    $this->usedAt[$app] = \microtime(true);
                    $this->idle[]       = $app;
                } else {
                    $app->flush();
                }
            }
        };
        $streamed = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;
        if ($streamed && !$this->virtual && !isset($context['detached'])) {
            // The callback runs while swerve reads the body: a coroutine of its own
            $context['detached'] = true;
            \phasync::go(function () use ($context, $response, $output, $after, $fail) {
                try {
                    $this->client->respond($context, $response, $output);
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
            $this->client->respond($context, $response, $output);
        } catch (\Throwable $e) {
            $fail($e);

            return;
        }
        // Without a coroutine of its own the request ends once swerve has sent the response
        isset($context['detached']) || $this->virtual ? $after() : \phasync::finally($after);
    }

    private function startSweeping(): void
    {
        $this->sweeping = true;
        \phasync::go(function () {
            $every = $this->idleSeconds / 6;
            $next  = \microtime(true) + $every;
            // Short sleeps: a draining worker waits for this coroutine to end
            while (!Swerve::draining()) {
                \phasync::sleep(\min(0.25, $every));
                if (\microtime(true) < $next) {
                    continue;
                }
                $next  = \microtime(true) + $every;
                $limit = \microtime(true) - $this->idleSeconds;
                while ($this->idle && $this->usedAt[$this->idle[0]] <= $limit) {
                    \array_shift($this->idle)->flush();
                }
            }
        }, context: new \stdClass());
    }

    /** A new application, bootstrapped as the HTTP kernel does, for the pool; it has no request yet. */
    private function boot(): Application
    {
        $app = require "$this->root/bootstrap/app.php";
        Current::set($app);
        $app->instance('request', Request::create('/'));
        $kernel = $app->make(HttpKernel::class);
        // HandleExceptions adds PHP error and exception handlers and a shutdown function, none
        // removable: the first application did that, and they go through Current
        $app->bootstrapWith(\array_values(\array_diff((new \ReflectionMethod($kernel, 'bootstrappers'))->invoke($kernel), [HandleExceptions::class])));
        Current::install($app);
        $this->client->prepare($app);
        // Octane's default does not delete uploads; PHP-FPM does
        $app['events']->listen(RequestTerminated::class, FlushUploadedFiles::class);
        foreach ($app['config']->get('octane.warm', []) as $service) {
            if (\is_string($service) && $app->bound($service)) {
                $app->make($service);
            }
        }
        $this->booted[$app] = \Closure::bind(fn () => [$this->instances, $this->reboundCallbacks], $app, Container::class)();

        return $app;
    }

    /**
     * Run $code with a new application, bootstrapped by the console kernel as `php artisan` does,
     * as this coroutine's Laravel application; then flush it.
     */
    private function withApplication(\Closure $code): mixed
    {
        $app = require "$this->root/bootstrap/app.php";
        Current::set($app);
        try {
            $kernel = $app->make(ConsoleKernel::class);
            $app->bootstrapWith(\array_values(\array_diff((new \ReflectionMethod($kernel, 'bootstrappers'))->invoke($kernel), [HandleExceptions::class])));
            Current::install($app);

            return $code($app);
        } finally {
            $app->flush();
            Current::unset();
        }
    }
}
