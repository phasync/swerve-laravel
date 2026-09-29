<?php

namespace Swerve\Laravel;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationGateway;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;

/**
 * Octane's Worker, one of a pool: an application of its own, serving one request at a time in
 * a sandbox cloned from it, as Octane does. The sandbox is the current application of the
 * request's coroutine (Current), instead of the process-wide one.
 *
 * @internal
 */
final class PoolWorker extends Worker
{
    /** Octane's Worker::handle(), with the sandbox made current for this coroutine only. */
    public function handle(Request $request, RequestContext $context): void
    {
        $sandbox = $this->enter();
        $gateway = new ApplicationGateway($this->app, $sandbox);
        try {
            $responded = false;
            \ob_start();
            $response = $gateway->handle($request);
            $output   = \ob_get_contents();
            if (\ob_get_level()) {
                \ob_end_clean();
            }
            $this->client->respond($context, new OctaneResponse($response, $output));
            $responded = true;
            $this->invokeRequestHandledCallbacks($request, $response, $sandbox);
            $gateway->terminate($request, $response);
        } catch (\Throwable $e) {
            $this->handleWorkerError($e, $sandbox, $request, $context, $responded);
        } finally {
            $this->leave($sandbox);
        }
    }

    /** Octane's task: $callback in a sandbox of its own. */
    public function task(\Closure $callback): mixed
    {
        $sandbox = $this->enter();
        $result  = null;
        try {
            $sandbox['events']->dispatch(new TaskReceived($this->app, $sandbox, $callback));

            return $result = $callback();
        } finally {
            $sandbox['events']->dispatch(new TaskTerminated($this->app, $sandbox, $callback, $result));
            $this->leave($sandbox);
        }
    }

    private function enter(): Application
    {
        $sandbox = clone $this->app;
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        \phasync::getContext()[Current::KEY] = $sandbox;

        return $sandbox;
    }

    private function leave(Application $sandbox): void
    {
        $sandbox->flush();
        $this->app->make('view.engine.resolver')->forget('blade');
        $this->app->make('view.engine.resolver')->forget('php');
        unset(\phasync::getContext()[Current::KEY]);
    }
}
