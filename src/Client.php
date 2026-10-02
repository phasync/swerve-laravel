<?php

namespace Swerve\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Laravel\Octane\Contracts\Client as OctaneClient;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use phasync\CancelledException;
use phasync\Psr\Response;
use phasync\Psr\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Octane's server side for swerve: turns swerve's PSR-7 requests into Laravel requests, and
 * Laravel's responses into PSR-7 responses handed to the waiting Handler::handle().
 *
 * @internal
 */
final class Client implements OctaneClient
{
    private string $public;

    public function __construct(private readonly string $basePath = '')
    {
    }

    /**
     * The requests in flight. Laravel keeps its last request after it ended: weak, so that
     * swerve's request (and its uploaded files) goes when the request is over.
     *
     * @var \WeakMap<Request, \WeakReference<RequestContext>>
     */
    private \WeakMap $contexts;

    public function boot(Application $app): void
    {
        $this->public   = $app->publicPath();
        $this->contexts = new \WeakMap();
        // A route that asks for a ServerRequestInterface gets swerve's own request; for an
        // upgrade its body is the connection, and the route may return WebSocket::from(...)
        $app['events']->listen(RequestReceived::class, function (RequestReceived $event) {
            $event->sandbox->instance(ServerRequestInterface::class, $this->contexts[$event->request]->get()['psr']);
        });
        // Laravel turns a PSR-7 response into its own: a 101 is kept, and handed to swerve as it is
        $app['events']->listen(PreparingResponse::class, function (PreparingResponse $event) {
            if ($event->response instanceof ResponseInterface && 101 === $event->response->getStatusCode()) {
                $this->contexts[$event->request]->get()['upgrade'] = $event->response;
            }
        });
    }

    /**
     * The Laravel request for $context['psr'], as PHP-FPM and Request::capture() would make it.
     */
    public function marshalRequest(RequestContext $context): array
    {
        /** @var ServerRequestInterface $psr */
        $psr    = $context['psr'];
        $uri    = $psr->getUri();
        $method = $psr->getMethod();
        $server = [
            'SERVER_NAME'     => $uri->getHost(),
            'SERVER_PORT'     => $uri->getPort() ?? 80,
            'QUERY_STRING'    => $uri->getQuery(),
            'DOCUMENT_ROOT'   => $this->public,
            'SCRIPT_FILENAME' => $this->public . '/index.php',
            'SCRIPT_NAME'     => $this->basePath . '/index.php',
            'PHP_SELF'        => $this->basePath . '/index.php',
        ] + $psr->getServerParams();
        if ('' !== $this->basePath) {
            // Symfony finds the base URL by matching SCRIPT_NAME against the request URI
            $server['REQUEST_URI'] = $this->basePath . $server['REQUEST_URI'];
        }
        foreach ($psr->getHeaders() as $name => $values) {
            $key          = \strtoupper(\strtr($name, '-', '_'));
            $key          = 'CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key ? $key : "HTTP_$key";
            $server[$key] = \implode('HTTP_COOKIE' === $key ? '; ' : ', ', $values);
        }

        $upgrade = $psr->hasHeader('Upgrade') && \str_contains(\strtolower($psr->getHeaderLine('Connection')), 'upgrade');
        if ($upgrade) {
            // The body is what the client sends after the handshake: the route reads it
            $post    = [];
            $files   = [];
            $content = '';
        } else {
            // The form first: after it, the body is what php://input would be
            $post  = $psr->getParsedBody() ?? [];
            $files = $psr->getUploadedFiles();
            // Swerve's temporary files stay while swerve's request lives, and are deleted with
            // it unless moved; Octane's is_uploaded_file() and move_uploaded_file() accept them
            \array_walk_recursive($files, static function (UploadedFileInterface &$file) {
                $error = $file->getError();
                $file  = new UploadedFile(\UPLOAD_ERR_OK === $error ? $file->getStream()->getMetadata('uri') : '', (string) $file->getClientFilename(), $file->getClientMediaType(), $error);
            });
            $content = (string) $psr->getBody();
            if (\in_array($method, ['PUT', 'PATCH', 'DELETE', 'QUERY'], true) && \str_starts_with(\strtolower($psr->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
                \parse_str($content, $post);
            }
            // Swerve's body was read: the route's ServerRequestInterface gets its bytes again
            $context['psr'] = $psr->withBody(StreamFactory::create($content))->withParsedBody($post ?: $psr->getParsedBody());
        }

        $request                  = Request::createFromBase(new SymfonyRequest($psr->getQueryParams(), $post, [], $psr->getCookieParams(), $files, $server, $content));
        $this->contexts[$request] = \WeakReference::create($context);

        return [$request, $context];
    }

    public function respond(RequestContext $context, OctaneResponse $octaneResponse): void
    {
        $response = $octaneResponse->response;
        $status   = $response->getStatusCode();
        if (101 === $status && isset($context['upgrade'])) {
            $this->hand($context, $context['upgrade']);

            return;
        }
        $headers = $response->headers->allPreserveCaseWithoutCookies();
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }
        // What the request echoed goes first, as under PHP-FPM (as Octane does, not for files)
        $output = $response instanceof BinaryFileResponse ? '' : (string) $octaneResponse->outputBuffer;
        if (!$response instanceof StreamedResponse && !$response instanceof BinaryFileResponse) {
            $this->hand($context, new Response($status, $headers, $output . $response->getContent()));

            return;
        }
        if ('HEAD' === $context['psr']->getMethod() || 204 === $status || 304 === $status) {
            $this->hand($context, new Response($status, $headers, '')); // swerve won't read a body

            return;
        }

        // Streamed: the head goes now, and each piece as the callback echoes it
        $pipe = new Pipe();
        $this->hand($context, new Response($status, $headers, new StreamedBody($pipe)));
        $fiber = \Fiber::getCurrent();
        $gone  = new class('The client left') extends CancelledException {
            /** Not an error: Laravel's exception handler logs nothing when this returns. */
            public function report(): void
            {
            }
        };
        $streaming = true;  // the callback runs: it may be cancelled
        $cancelled = false;
        $level     = \ob_get_level();
        \ob_start(static function (string $chunk) use ($pipe, $fiber, $gone, &$streaming, &$cancelled): string {
            // An output handler must not throw: the client having left, the callback is
            // cancelled where it next waits (sleep(), a query, ...), not here
            if ('' !== $chunk && !$pipe->write($chunk) && !$cancelled) {
                $cancelled = true;
                \phasync::go(static function () use ($fiber, $gone, &$streaming) {
                    \phasync::sleep(); // until the callback waits
                    $streaming && $fiber->isSuspended() && \phasync::cancel($fiber, $gone);
                });
            }

            return '';
        }, 1);
        try {
            '' !== $output && $pipe->write($output);
            $response->sendContent();
        } catch (\Throwable $e) {
            if ($e !== $gone) {
                $pipe->failed = true; // the client sees the response cut off, not complete
                throw $e;
            }
        } finally {
            $streaming = false;
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }
            $pipe->end();
        }
    }

    public function error(\Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        $this->hand($context, new Response(500, ['Content-Type' => 'text/plain'], Octane::formatExceptionForClient($e, (bool) $app['config']->get('app.debug'))));
    }

    /**
     * Give swerve the response, and wait until Handler::handle() returned it: the rest of the
     * request (terminate(), defer(), a streamed body) runs once swerve is sending it.
     */
    private function hand(RequestContext $context, ResponseInterface $response): void
    {
        if (!isset($context['handed'])) { // not again for an error after a streamed response began
            $context['handed']   = true;
            $context['response'] = $response;
            \phasync::raiseFlag($context);
            while (!isset($context['returned'])) {
                \phasync::awaitFlag($context);
            }
        }
    }
}
