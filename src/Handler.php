<?php

namespace Swerve\Laravel;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Virtual;

/**
 * A Laravel application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Laravel\Handler(__DIR__);
 *
 * Every request runs as under PHP-FPM, in an application of its own: bootstrap/app.php is
 * required, the HTTP kernel bootstraps and handles the request, and terminates it (terminate()
 * and defer() callbacks, after the response went to swerve). Then the application is flushed and
 * dropped. Requests share the worker's classes and opcache, and PHP's static properties.
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

    /** Each request runs in phasync-ext's virtualize(): its output buffers are its own */
    private readonly bool $virtual;

    /**
     * @param string $root     the application's root directory, where composer.json is
     * @param string $basePath the folder the application is served under when a reverse proxy
     *                         strips it before swerve sees the request, such as '/demos/app';
     *                         url(), redirects and signed URLs then include it, as under PHP-FPM
     */
    public function __construct(private readonly string $root, private readonly string $basePath = '')
    {
        // The first application only learns the application's class and warms opcache
        $app = require "$root/bootstrap/app.php";
        $app->make(ConsoleKernel::class)->bootstrap();
        Current::install($app);
        $this->client  = new Client($app->publicPath(), \rtrim($basePath, '/'));
        $this->virtual = Virtual::available();
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
            static fn () => $handler->withApplication(ConsoleKernel::class, static fn () => $callback(), null),
            context: new \stdClass(), // its own, so that the application is its own
        ));

        return $task();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Reading and parsing the body happens here, before the application is made
        [$laravelRequest, $context] = $this->client->marshalRequest($request);
        \phasync::go(function () use ($laravelRequest, $context) {
            try {
                $serve = fn () => $this->serve($laravelRequest, $context);
                $this->virtual ? Virtual::run($context['psr'], $serve) : $serve();
            } finally {
                $context['done'] = true;
                \phasync::raiseFlag($context);
            }
        });
        while (!isset($context['handed']) && !isset($context['done'])) {
            \phasync::awaitFlag($context);
        }
        $response = $context['response'] ?? throw new \RuntimeException('The Laravel application ended without a response');
        // Only swerve holds a streamed body now: dropping it tells the producer the client left
        unset($context['response']);
        $context['returned'] = true;
        \phasync::raiseFlag($context);

        return $response;
    }

    /** One request, in an application of its own. */
    private function serve(Request $request, \ArrayObject $context): void
    {
        $this->withApplication(HttpKernel::class, function (Application $app) use ($request, $context) {
            $this->client->bind($app, $context);
            $kernel    = $app->make(HttpKernel::class);
            $responded = false;
            try {
                \ob_start();
                $response = $kernel->handle($request);
                $output   = \ob_get_contents();
                if (\ob_get_level()) {
                    \ob_end_clean();
                }
                $this->client->respond($context, $response, $output);
                $responded = true;
                $kernel->terminate($request, $response);
            } catch (\Throwable $e) {
                $responded || $this->client->error($context, $e, (bool) $app['config']->get('app.debug'));
                $app[ExceptionHandler::class]->report($e);
            }
        }, $request);
    }

    /**
     * Run $code with a new application, bootstrapped by the kernel that $kernel names, as this
     * coroutine's Laravel application; then flush it. The request is bound before the providers
     * boot, as the HTTP kernel does, for those that use it (URL::forceRootUrl()).
     */
    private function withApplication(string $kernel, \Closure $code, ?Request $request): mixed
    {
        $app = require "$this->root/bootstrap/app.php";
        Current::set($app);
        try {
            $request && $app->instance('request', $request);
            $kernel = $app->make($kernel);
            // HandleExceptions adds PHP error and exception handlers and a shutdown function, none
            // removable: the first application did that, and they go through Current
            $app->bootstrapWith(\array_values(\array_diff((new \ReflectionMethod($kernel, 'bootstrappers'))->invoke($kernel), [HandleExceptions::class])));
            Current::install($app);

            return $code($app);
        } finally {
            $app->flush();
            Current::unset();
        }
    }
}
