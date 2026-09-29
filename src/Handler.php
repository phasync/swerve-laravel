<?php

namespace Swerve\Laravel;

use Illuminate\Container\Container;
use Illuminate\Pagination\PaginationState;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\RequestContext;
use phasync\Context\DefaultContext;
use phasync\Util\Pool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Virtual;

/**
 * A Laravel application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Laravel\Handler(__DIR__);
 *
 * Octane's own Worker boots the application and dispatches WorkerStarting. Per request, the
 * Worker clones the booted application into a sandbox, dispatches Octane's events
 * (RequestReceived, RequestHandled, RequestTerminated, OperationTerminated) to Octane's listeners
 * and to those of packages such as Livewire, Inertia and Sentry, and flushes the sandbox. The
 * response goes to swerve as soon as it exists; terminate() and defer() callbacks run after that.
 *
 * A booted application serves one request at a time: its sandboxes share its router, session
 * store, auth guards, translator, view factory, cookie queue and database connections. With
 * phasync-ext's virtualize() (each request's output buffers its own), a worker boots more of
 * them, up to $apps, while requests overlap; Laravel's process-wide pointers to "the"
 * application (app(), the facades, Eloquent's connection resolver, ...) follow the request's
 * coroutine (Current). Without it, a worker serves one request at a time; the others wait
 * without blocking the worker's connections. See docs/concurrency.md.
 */
final class Handler implements RequestHandlerInterface
{
    /** The worker's handler, for run() */
    private static self $current;

    private readonly Client $client;

    /** @var Pool<PoolWorker> */
    private readonly Pool $workers;

    /** Each request runs in phasync-ext's virtualize(): its output buffers are its own */
    private readonly bool $virtual;

    /**
     * @param string $root the application's root directory, where composer.json is
     * @param int    $apps the most applications per worker, so the most requests served at once
     *                     with phasync-ext; without it, one
     */
    public function __construct(string $root, int $apps = 16)
    {
        $this->client  = $client = new Client();
        $this->virtual = Virtual::available();
        $factory       = new ApplicationFactory($root);
        $this->workers = new Pool(static function () use ($factory, $client) {
            $worker = new PoolWorker($factory, $client);
            $worker->boot();
            $client->boot($worker->application());
            Current::$fallback ??= $worker->application();
            Current::install(); // the boot pointed Laravel's globals at the new application
            // Blade::render() registers this namespace once per process, on the first application
            $app = $worker->application();
            $app['view']->addNamespace('__components', $app['config']->get('view.compiled'));
            // Octane gives the paginator the sandbox on each request: the proxy instead
            $worker->application()['events']->listen(RequestReceived::class, static fn () => PaginationState::resolveUsing(Container::getInstance()));
            // WorkerStopping: the worker's exit runs shutdown functions, after its last request
            \register_shutdown_function($worker->terminate(...));

            return $worker;
        }, $this->virtual ? $apps : 1);
        \phasync::run(fn () => $this->workers->release($this->workers->borrow()));
        self::$current = $this;
    }

    /**
     * Run Laravel code outside a request, such as in a WebSocket callback, as Octane runs a
     * task: in a fresh copy of an application of its own, which no request uses meanwhile.
     * Returns what $callback returns or throws what it throws.
     *
     *     foreach ($ws as $message) {
     *         Handler::run(fn () => Message::create(['user_id' => $userId, 'text' => $message]));
     *     }
     *
     * The copy has no request, so no session or user (as in an Octane task): take the user
     * before WebSocket::from().
     */
    public static function run(\Closure $callback): mixed
    {
        $workers = self::$current->workers;
        $worker  = $workers->borrow();
        try {
            return \phasync::await(\phasync::go(static fn () => $worker->task($callback), context: new DefaultContext()));
        } finally {
            $workers->release($worker);
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Reading and parsing the body happens here, before waiting for an application. The
        // request's coroutines share swerve's phasync context for it, where Current finds its application
        [$laravelRequest, $context] = $this->client->marshalRequest(new RequestContext(['psr' => $request]));
        \phasync::go(function () use ($laravelRequest, $context) {
            try {
                $worker = $this->workers->borrow();
                try {
                    $run = static fn () => $worker->handle($laravelRequest, $context);
                    $this->virtual ? Virtual::run($context['psr'], $run) : $run();
                } finally {
                    $this->workers->release($worker);
                }
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
