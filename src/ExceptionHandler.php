<?php

namespace Swerve\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Swerve\WorkerStoppingException;

/**
 * The application's exception handler, which a stopping worker's WorkerStoppingException passes:
 * it is no error of the application's, so it is not reported, and rendering it throws it on, out of
 * the HTTP kernel, for swerve to answer (a 503 when nothing was sent yet).
 *
 * @internal
 */
final class ExceptionHandler implements ExceptionHandlerContract
{
    public function __construct(private readonly ExceptionHandlerContract $handler)
    {
    }

    public function report(\Throwable $e)
    {
        $e instanceof WorkerStoppingException || $this->handler->report($e);
    }

    public function shouldReport(\Throwable $e)
    {
        return !$e instanceof WorkerStoppingException && $this->handler->shouldReport($e);
    }

    public function render($request, \Throwable $e)
    {
        return $e instanceof WorkerStoppingException ? throw $e : $this->handler->render($request, $e);
    }

    public function renderForConsole($output, \Throwable $e)
    {
        $this->handler->renderForConsole($output, $e);
    }

    /** The decorated handler's other methods: reportable(), renderable(), ... */
    public function __call(string $method, array $args): mixed
    {
        return $this->handler->$method(...$args);
    }
}
