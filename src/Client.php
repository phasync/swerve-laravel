<?php

namespace Swerve\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Events\PreparingResponse;
use phasync\CancelledException;
use phasync\Psr\Response;
use phasync\Psr\StringStream;
use phasync\Util\Synchronized;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two sides of the exchange with swerve: turns its PSR-7 requests into Laravel requests, and
 * Laravel's responses into PSR-7 responses handed to the waiting Handler::handle().
 *
 * A request's state is an ArrayObject, its "context": 'psr' is swerve's request, 'respond' hands
 * a response to Virtual::run(), and 'handed', 'response', 'returned' and 'upgrade' tell
 * Handler::handle() and the coroutine running Laravel where they are.
 *
 * @internal
 */
final class Client
{
    /**
     * @param bool $virtual each request runs in phasync-ext's virtualize(), by Virtual::run() handing over its response
     */
    public function __construct(private readonly string $public, private readonly string $basePath = '', private readonly bool $virtual = false)
    {
    }

    /** Once per application: a route that returns swerve's 101 response gets it sent as it is. */
    public function prepare(Application $app): void
    {
        $app['events']->listen(PreparingResponse::class, static function (PreparingResponse $event) use ($app) {
            if ($event->response instanceof ResponseInterface && 101 === $event->response->getStatusCode()) {
                $app->make('swerve.context')['upgrade'] = $event->response;
            }
        });
    }

    /** Give the routes of $app swerve's request, and swerve a 101 response to send as it is. */
    public function bind(Application $app, \ArrayObject $context): void
    {
        // A route that asks for a ServerRequestInterface gets swerve's own request; for an
        // upgrade its body is the connection, and the route may return WebSocket::from(...)
        $app->instance(ServerRequestInterface::class, $context['psr']);
        $app->instance('swerve.context', $context);
    }

    /**
     * The Laravel request for $psr, as PHP-FPM and Request::capture() would make it, and the
     * context to run it in.
     *
     * @return array{0: Request, 1: \ArrayObject}
     */
    public function marshalRequest(ServerRequestInterface $psr): array
    {
        $context = new \ArrayObject(['psr' => $psr]);
        $uri     = $psr->getUri();
        $method  = $psr->getMethod();
        $server  = [
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
            // The route goes on running after it handed over the 101: a coroutine of its own
            $context['detached'] = true;
            // The body is what the client sends after the handshake: the route reads it
            $post    = [];
            $files   = [];
            $content = '';
        } else {
            // The form first: after it, the body is what php://input would be
            $post  = $psr->getParsedBody() ?? [];
            $files = $psr->getUploadedFiles();
            // Swerve's temporary files stay while swerve's request lives, and are deleted with
            // it unless moved. Test mode, as they were not uploaded to PHP: is_uploaded_file()
            // and move_uploaded_file() would refuse them
            \array_walk_recursive($files, static function (UploadedFileInterface &$file) {
                $error = $file->getError();
                $file  = new UploadedFile(\UPLOAD_ERR_OK === $error ? $file->getStream()->getMetadata('uri') : '', (string) $file->getClientFilename(), $file->getClientMediaType(), $error, true);
            });
            $content = (string) $psr->getBody();
            if (\in_array($method, ['PUT', 'PATCH', 'DELETE', 'QUERY'], true) && \str_starts_with(\strtolower($psr->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
                \parse_str($content, $post);
            }
            // Swerve's body was read: the route's ServerRequestInterface gets its bytes again
            $context['psr'] = $psr->withBody(new StringStream($content))->withParsedBody($post ?: $psr->getParsedBody());
        }

        return [Request::createFromBase(new SymfonyRequest($psr->getQueryParams(), $post, [], $psr->getCookieParams(), $files, $server, $content)), $context];
    }

    /** Hand $response to swerve. */
    public function respond(\ArrayObject $context, SymfonyResponse $response): void
    {
        $status   = $response->getStatusCode();
        if (101 === $status && isset($context['upgrade'])) {
            $this->hand($context, $context['upgrade']);

            return;
        }
        $headers = $response->headers->allPreserveCaseWithoutCookies();
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }
        if (!$response instanceof StreamedResponse && !$response instanceof BinaryFileResponse) {
            $this->hand($context, new Response($status, $headers, $response->getContent()));

            return;
        }
        if ('HEAD' === $context['psr']->getMethod() || 204 === $status || 304 === $status) {
            $this->hand($context, new Response($status, $headers)); // swerve won't read a body

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
        // Without phasync-ext output buffers are the worker's: the buffers of streams that overlap
        // would stack, and an echo reach the one that started last. One at a time then; the others wait
        $send = function () use ($response, $pipe, $fiber, $gone) {
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
        };
        $this->virtual ? $send() : Synchronized::run($this, $send);
    }

    /** A 500 for an exception the HTTP kernel did not turn into a response. */
    public function error(\ArrayObject $context, \Throwable $e, bool $debug): void
    {
        $this->hand($context, new Response(500, ['Content-Type' => 'text/plain'], $debug ? (string) $e : 'Internal server error.'));
    }

    /**
     * Give swerve the response. Under Virtual::run() that is its $respond closure, and the request goes on
     * running. Otherwise a request with a coroutine of its own waits until Handler::handle() returned it,
     * and handle() returns it as soon as the application is done.
     */
    private function hand(\ArrayObject $context, ResponseInterface $response): void
    {
        if (!isset($context['handed'])) { // not again for an error after a streamed response began
            $context['handed'] = true;
            if ($this->virtual) {
                $context['respond']($response);

                return;
            }
            $context['response'] = $response;
            if (isset($context['detached'])) {
                \phasync::raiseFlag($context);
                while (!isset($context['returned'])) {
                    \phasync::awaitFlag($context);
                }
            }
        }
    }
}
