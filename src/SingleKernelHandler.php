<?php

namespace Swerve\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Swerve\ClientRequest;
use Swerve\Laravel\ExceptionHandler as SwerveExceptionHandler;
use Swerve\WorkerStoppingException;

/**
 * Runs a bare Laravel application, or its HTTP kernel, that the application built and booted
 * itself, given to {@see \Swerve\PersistentMode} directly instead of {@see serve()}'s pooled
 * {@see Handler}. One application, so one request at a time: {@see LaravelAdapter} refuses it in
 * `AsyncMode`, and `PersistentMode` itself runs its handler one request at a time per worker, so
 * nothing here needs its own lock, and none of the pool's machinery ({@see AppState},
 * {@see Current}) applies: there is only ever one application, so Laravel's own process-wide
 * pointers already point at it.
 *
 * Octane's listeners (`RequestReceived`, `RequestTerminated`) reset the application between
 * requests, as {@see Handler} does for its pooled applications; `$_SERVER['LARAVEL_OCTANE']` makes
 * Laravel hand a stream response's generator callback over as it is, for {@see Client} to run. Set
 * again in handle(): swerve fills `$_SERVER` fresh for every request (the same environment every
 * mode gives one, per MODES.md), which would otherwise drop this between requests.
 *
 * @internal built once per bare application or kernel by {@see LaravelAdapter}
 */
final class SingleKernelHandler
{
    private readonly Application $app;
    private readonly HttpKernel $kernel;
    private readonly Client $client;

    public function __construct(Application|HttpKernel $handler)
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;
        if ($handler instanceof Application) {
            $this->app    = $handler;
            $this->kernel = $handler->make(HttpKernel::class);
        } else {
            $this->kernel = $handler;
            $this->app    = app();
        }
        $this->client = new Client($this->app->publicPath());
        // A worker that stops ends its requests with a WorkerStoppingException: swerve answers it, not Laravel
        $this->app->extend(ExceptionHandler::class, static fn (ExceptionHandler $handler) => new SwerveExceptionHandler($handler));
    }

    public function handle(ClientRequest $client): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;
        $request                   = $this->client->toLaravel($client);
        $fail                      = function (\Throwable $e) use ($client): void {
            if ($e instanceof WorkerStoppingException) {
                throw $e; // for swerve to end the exchange
            }
            $client->headersSent() ? $client->close() : $this->client->error($client, $e, (bool) $this->app['config']->get('app.debug'));
            $this->app[ExceptionHandler::class]->report($e);
        };
        try {
            $request->enableHttpMethodParameterOverride();
            $this->app['events']->dispatch(new RequestReceived($this->app, $this->app, $request));
            $response = $this->kernel->handle($request);
            if ($response instanceof WebSocketResponse) {
                // The worker goes on to the socket's handler, not another request, so nothing to release
                $client->sendResponseHeaders(101, $response->headers->allPreserveCaseWithoutCookies());
                $client->flush();
            } else {
                $this->client->send($client, $request, $response);
            }
        } catch (\Throwable $e) {
            $fail($e);

            return;
        }
        try {
            $this->kernel->terminate($request, $response);
            $this->app['events']->dispatch(new RequestTerminated($this->app, $this->app, $request, $response));
            $request->route()?->flushController();
        } catch (\Throwable $e) {
            $this->app[ExceptionHandler::class]->report($e);
        }
        if ($response instanceof WebSocketResponse) {
            $response->run($client);
        }
    }
}
