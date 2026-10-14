<?php

namespace Swerve\Laravel;

use Illuminate\Http\Request;
use Swerve\ClientRequest;
use Swerve\WebSocket;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response that upgrades a Laravel request to a WebSocket: a route returns it.
 *
 *     Route::get('/echo', fn (Request $request) => WebSocketResponse::from($request, function (WebSocket $ws) {
 *         foreach ($ws as $message) {
 *             $ws->send("echo: $message");
 *         }
 *     }));
 *
 * from() checks the handshake with swerve's own decision ({@see WebSocket::handshake()}) and
 * returns an ordinary response either way: the refusal swerve would send (426 for a plain GET,
 * 400 for an invalid handshake, 403 for an origin that is not allowed), or a 101 with the
 * handshake's headers, which middleware sees as any other response. Once the 101 is sent, the
 * request's application goes back to the pool and the handler runs on swerve's WebSocket, for
 * as long as the connection is open, in the request's scope but outside any application.
 */
final class WebSocketResponse extends Response
{
    /** @param \Closure(WebSocket): void $handler */
    private function __construct(array $headers, private readonly \Closure $handler, private readonly ?string $subprotocol, private readonly int $maxMessage)
    {
        parent::__construct('', 101, $headers);
    }

    /**
     * The response to a WebSocket request: a 101 that upgrades it and runs $handler, or the refusal.
     *
     * @param callable(WebSocket): void $handler
     * @param string[]                  $subprotocols the subprotocols you speak, in your order of preference: the first one the client offered is chosen
     * @param string[]|null             $origins      the allowed Origins (compared case-insensitively), null: any; a client that sends none is let through
     * @param int                       $maxMessage   the largest message received, in bytes; a larger one closes with 1009
     */
    public static function from(Request $request, callable $handler, array $subprotocols = [], ?array $origins = null, int $maxMessage = WebSocket::MAX_MESSAGE): Response
    {
        $length    = $request->headers->get('Content-Length');
        $hasBody   = null !== $length ? (int) $length > 0 : $request->headers->has('Transfer-Encoding');
        $version   = \substr((string) $request->server->get('SERVER_PROTOCOL', 'HTTP/1.1'), 5);
        $handshake = WebSocket::handshake($request->getMethod(), $version, $request->headers->all(), $hasBody, $subprotocols, $origins);
        // swerve's header names are lowercase; Laravel sends them as given, and clients expect them capitalized
        $headers = [];
        foreach ($handshake->headers as $name => $value) {
            $headers[\str_replace('Websocket', 'WebSocket', \ucwords($name, '-'))] = $value;
        }
        if (!$handshake->accepted()) {
            return new Response($handshake->body, $handshake->status, $headers);
        }
        $subprotocol = $handshake->headers['sec-websocket-protocol'] ?? null;

        return new self($headers, $handler(...), \is_array($subprotocol) ? $subprotocol[0] : $subprotocol, $maxMessage);
    }

    /** Run the handler on $client, whose 101 was sent: until the connection closes. */
    public function run(ClientRequest $client): void
    {
        WebSocket::run($client, $this->handler, $this->subprotocol, $this->maxMessage);
    }
}
