<?php

namespace Swerve\Laravel;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use phasync\CancelledException;
use phasync\IOException;
use phasync\Psr\ServerRequest as PsrServerRequest;
use phasync\Util\Synchronized;
use Psr\Http\Message\UploadedFileInterface;
use Swerve\ClientRequest;
use Swerve\Psr\FormBody;
use Swerve\Psr\RequestBody;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two sides of the exchange with swerve: turns its ClientRequest into a Laravel request, and
 * writes Laravel's response to it.
 *
 * @internal
 */
final class Client
{
    public function __construct(private readonly string $public, private readonly string $basePath = '')
    {
    }

    /** The Laravel request for $client, as PHP-FPM and Request::capture() would make it. */
    public function toLaravel(ClientRequest $client): Request
    {
        $method  = $client->getMethod();
        $target  = $client->getTarget();
        $headers = $client->getRequestHeaders();
        $https   = 'https' === $client->getScheme();
        $host    = $headers['host'][0] ?? '';
        $port    = $https ? 443 : 80;
        if (\preg_match('/^(.*):(\d+)$/', $host, $m)) {
            [, $host, $port] = $m;
        }
        $now    = \microtime(true);
        $query  = \parse_url($target, \PHP_URL_QUERY) ?: '';
        $server = [
            'SERVER_NAME'        => $host,
            'SERVER_PORT'        => (int) $port,
            'REQUEST_METHOD'     => $method,
            // Symfony finds the base URL by matching SCRIPT_NAME against the request URI
            'REQUEST_URI'        => $this->basePath . $target,
            'QUERY_STRING'       => $query,
            'SERVER_PROTOCOL'    => 'HTTP/' . $client->getProtocolVersion(),
            'REQUEST_TIME'       => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
            'DOCUMENT_ROOT'      => $this->public,
            'SCRIPT_FILENAME'    => $this->public . '/index.php',
            'SCRIPT_NAME'        => $this->basePath . '/index.php',
            'PHP_SELF'           => $this->basePath . '/index.php',
        ];
        if ($https) {
            $server['HTTPS'] = 'on';
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->peer(), $peer)) {
            $server['REMOTE_ADDR'] = $peer[1];
            $server['REMOTE_PORT'] = (int) $peer[2];
        }
        foreach ($headers as $name => $values) {
            $key          = \strtoupper(\strtr($name, '-', '_'));
            $key          = 'CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key ? $key : "HTTP_$key";
            $server[$key] = \implode('HTTP_COOKIE' === $key ? '; ' : ', ', $values);
        }
        $get = [];
        \parse_str($query, $get);
        $cookies = isset($server['HTTP_COOKIE']) ? PsrServerRequest::cookies($server['HTTP_COOKIE']) : [];

        // An upgrade (a WebSocket handshake) has no body: what follows is the connection's, which
        // the WebSocket reads once the route answered with a 101
        if (isset($headers['upgrade']) && !isset($headers['content-length']) && !isset($headers['transfer-encoding'])) {
            return Request::createFromBase(new SymfonyRequest($get, [], [], $cookies, [], $server));
        }
        $body = new RequestBody($client, isset($headers['transfer-encoding']) ? null : (int) ($headers['content-length'][0] ?? 0));
        $form = isset($headers['content-type']) ? FormBody::for($method, $headers['content-type'][0], $body) : null;
        // The form first: after it, the body is what php://input would be
        $post = $form?->fields() ?? [];
        // swerve's temporary files stay while the form lives, and are deleted with it unless moved.
        // Test mode, as they were not uploaded to PHP: is_uploaded_file() and move_uploaded_file() would refuse them
        $files = $form?->files() ?? [];
        \array_walk_recursive($files, static function (UploadedFileInterface &$file) {
            $error = $file->getError();
            $file  = new UploadedFile(\UPLOAD_ERR_OK === $error ? $file->getStream()->getMetadata('uri') : '', (string) $file->getClientFilename(), $file->getClientMediaType(), $error, true);
        });
        $content = $form ? $form->input()->getContents() : $body->getContents();
        if (\in_array($method, ['PUT', 'PATCH', 'DELETE', 'QUERY'], true) && \str_starts_with(\strtolower($server['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')) {
            \parse_str($content, $post);
        }
        $request = Request::createFromBase(new SymfonyRequest($get, $post, [], $cookies, $files, $server, $content));
        // The uploads' temporary files live as long as the request
        null !== $form && $request->attributes->set(FormBody::class, $form);

        return $request;
    }

    /**
     * Write $response to the client and end it: the head, then the body, a stream's as its
     * callback makes it.
     */
    public function send(ClientRequest $client, Request $request, SymfonyResponse $response): void
    {
        $status  = $response->getStatusCode();
        $headers = $response->headers->allPreserveCaseWithoutCookies();
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }
        $empty  = 'HEAD' === $request->getMethod() || $response->isInformational() || $response->isEmpty();
        $stream = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;
        if ($empty || !$stream) {
            $content = $empty ? '' : (string) $response->getContent();
            if (!$empty && !isset($headers['Content-Length']) && !isset($headers['content-length'])) {
                $headers['Content-Length'] = (string) \strlen($content);
            }
            $client->sendResponseHeaders($status, $headers);
            '' !== $content && $client->write($content);
            $client->end();

            return;
        }
        $client->sendResponseHeaders($status, $headers);
        if ($response instanceof BinaryFileResponse) {
            self::sendFile($client, $response);
        } else {
            $this->sendStreamed($client, $response);
        }
        $client->end();
    }

    /** A 500 for an exception the HTTP kernel did not turn into a response, unless the head went already. */
    public function error(ClientRequest $client, \Throwable $e, bool $debug): void
    {
        if (!$client->headersSent()) {
            $body = $debug ? (string) $e : 'Internal server error.';
            $client->sendResponseHeaders(500, ['Content-Type' => 'text/plain', 'Content-Length' => (string) \strlen($body)]);
            $client->write($body);
            $client->end();
        }
    }

    /**
     * A StreamedResponse's body, as its callback makes it. A client that leaves cancels the callback
     * where it next waits.
     */
    private function sendStreamed(ClientRequest $client, StreamedResponse $response): void
    {
        $gone = new class('The client left') extends CancelledException {
            /** Not an error: Laravel's exception handler logs nothing when this returns. */
            public function report(): void
            {
            }
        };
        // A generator callback (Laravel hands it over as it is when LARAVEL_OCTANE is set) yields its
        // chunks: they are written with no output buffer, so streams overlap
        $callback = $response->getCallback();
        if ($callback instanceof \Closure && (new \ReflectionFunction($callback))->isGenerator()) {
            try {
                foreach ($callback() as $chunk) {
                    '' !== ($chunk = (string) $chunk) && $client->write($chunk);
                }
            } catch (IOException) {
                throw $gone; // the generator's finally blocks run as it is released
            }

            return;
        }
        // An echoing callback writes into an output buffer, which belongs to the process: the buffers
        // of streams that overlap would stack, and an echo reach the one that started last. One at a
        // time then; the others wait. The buffer's handler must not wait: a coroutine of its own
        // writes what it collects to the client
        Synchronized::run($this, static function () use ($client, $response, $gone) {
            $pipe   = new Pipe();
            $fiber  = \phasync\ext\current_fiber();
            $writer = \phasync::go(static function () use ($client, $pipe, $fiber, $gone) {
                try {
                    while (!$pipe->ended || '' !== $pipe->buffer) {
                        if ('' === $pipe->buffer) {
                            \phasync::awaitFlag($pipe);
                            continue;
                        }
                        [$bytes, $pipe->buffer] = [$pipe->buffer, ''];
                        $client->write($bytes);
                    }
                } catch (IOException) {
                    $pipe->gone = true;
                    $pipe->ended || \phasync::cancel($fiber, $gone);
                }
            });
            $level = \ob_get_level();
            \ob_start(static function (string $chunk) use ($pipe): string {
                if ('' !== $chunk && !$pipe->gone) {
                    $pipe->buffer .= $chunk;
                    \phasync::raiseFlag($pipe);
                }

                return '';
            }, 1);
            try {
                $response->sendContent();
            } finally {
                while (\ob_get_level() > $level) {
                    \ob_end_clean();
                }
                $pipe->ended = true;
                \phasync::raiseFlag($pipe);
            }
            \phasync::await($writer);
            if ($pipe->gone) {
                throw $gone;
            }
        });
    }

    /** What BinaryFileResponse would stream, sent with ClientRequest::sendFile(): a Range included. */
    private static function sendFile(ClientRequest $client, BinaryFileResponse $response): void
    {
        [$offset, $length, $temporary, $delete] = (fn () => [$this->offset, $this->maxlen, $this->tempFileObject ?? null, $this->deleteFileAfterSend])->call($response);
        $path                                   = $response->getFile()->getPathname();
        try {
            if (!$response->isSuccessful() || 0 === $length) {
                return;
            }
            if (null !== $temporary) {
                $client->sendFile($temporary->getResource(), $offset, $length > 0 ? $length : null);
            } else {
                $stream = \fopen($path, 'rb');
                try {
                    $client->sendFile($stream, $offset, $length > 0 ? $length : null);
                } finally {
                    \fclose($stream);
                }
            }
        } finally {
            if (null === $temporary && $delete && \is_file($path)) {
                \unlink($path);
            }
        }
    }
}
