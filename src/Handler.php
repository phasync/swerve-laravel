<?php

namespace Swerve\Laravel;

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use phasync\Util\Synchronized;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A Laravel application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Laravel\Handler(__DIR__);
 *
 * Once per worker, Octane's own Worker boots the application and dispatches WorkerStarting.
 * Per request, the Worker clones the booted application into a sandbox, dispatches Octane's
 * events (RequestReceived, RequestHandled, RequestTerminated, OperationTerminated) to Octane's
 * listeners and to those of packages such as Livewire, Inertia and Sentry, and flushes the
 * sandbox. Laravel keeps the current request, session, user and container in process-wide
 * globals, so a worker runs one Laravel request at a time; the others wait for their turn
 * without blocking the worker's connections. The response goes to swerve as soon as it exists,
 * and terminate() and defer() callbacks run after that, still holding the turn.
 */
final class Handler implements RequestHandlerInterface
{
    private readonly Client $client;
    private readonly Worker $worker;

    /**
     * @param string $root the application's root directory, where composer.json is
     */
    public function __construct(string $root)
    {
        $this->client = new Client();
        $this->worker = new Worker(new ApplicationFactory($root), $this->client);
        $this->worker->boot();
        $this->client->boot($this->worker->application());
        // WorkerStopping: the worker's exit runs shutdown functions, after its last request
        \register_shutdown_function($this->worker->terminate(...));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Reading and parsing the body happens here, before waiting for the turn
        [$laravelRequest, $context] = $this->client->marshalRequest(new RequestContext(['psr' => $request]));
        \phasync::go(function () use ($laravelRequest, $context) {
            try {
                Synchronized::run($this, fn () => $this->worker->handle($laravelRequest, $context));
            } finally {
                $context['done'] = true;
                \phasync::raiseFlag($context);
            }
        });
        while (!isset($context['handed']) && !isset($context['done'])) {
            \phasync::awaitFlag($context);
        }
        $response = $context['response'] ?? throw new \RuntimeException('The Laravel worker ended without a response');
        // Only swerve holds a streamed body now: dropping it tells the producer the client left
        unset($context['response']);
        $context['returned'] = true;
        \phasync::raiseFlag($context);

        return $response;
    }
}
