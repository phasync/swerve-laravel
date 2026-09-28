<?php

/*
 * The tests run the Laravel application in tests/Fixtures/app (made by tests/create-app.sh) on
 * a real swerve, the way users run it. SWERVE_PHP_ARGS adds PHP options, such as loading
 * phasync-ext: CI runs the suite without and with it. SWERVE_TEST_PORTS is the range of ports
 * the tests may listen on (default 18700-18749).
 */

const APP = __DIR__ . '/Fixtures/app';

/**
 * Start swerve on a free port with the fixture application and wait until it answers.
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address, its log file
 */
function app_start(int $workers = 2, array $env = []): array
{
    [$from, $to] = \array_map('intval', \explode('-', \getenv('SWERVE_TEST_PORTS') ?: '18700-18749'));
    for ($port = $from; $port <= $to; ++$port) {
        if ($socket = @\stream_socket_server("tcp://127.0.0.1:$port")) {
            \fclose($socket);
            break;
        }
    }
    $addr     = "127.0.0.1:$port";
    $log      = \tempnam(\sys_get_temp_dir(), 'swerve-log');
    $php      = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd      = 'exec ' . \PHP_BINARY . " $php " . \escapeshellarg(APP . '/vendor/bin/swerve') . " --workers=$workers --grace=5 --http=$addr --log=" . \escapeshellarg($log) . ' ' . \escapeshellarg(APP . '/swerve.php');
    $proc     = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, APP, $env + \getenv());
    $deadline = \microtime(true) + 20;
    $ch       = \curl_init("http://$addr/json");
    \curl_setopt_array($ch, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 1]);
    while (false === \curl_exec($ch)) {
        if (\microtime(true) > $deadline) {
            throw new RuntimeException("swerve did not start:\n" . \file_get_contents($log));
        }
        \usleep(100_000);
    }

    return [$proc, $addr, $log];
}

/** Stop swerve as SIGTERM does (a graceful drain), and return its exit code. */
function app_stop($proc): int
{
    \proc_terminate($proc, \SIGTERM);

    return app_wait($proc);
}

/** Wait for swerve to exit, and return its exit code. */
function app_wait($proc): int
{
    $deadline = \microtime(true) + 10;
    while (($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }
    $code = \proc_close($proc);

    // Before PHP 8.3 only the proc_get_status() that saw the exit has the exit code
    return $status['running'] ? $code : $status['exitcode'];
}

/** Run $test against a swerve serving the fixture application, then stop it; returns its log. */
function with_app(Closure $test, int $workers = 2, array $env = []): string
{
    [$proc, $addr, $log] = app_start($workers, $env);
    try {
        $test($addr);
    } finally {
        app_stop($proc);
    }
    $contents = \file_get_contents($log);
    \unlink($log);

    return $contents;
}

/** An HTTP client with a cookie jar: a browser's session. Every request on a new connection. */
final class Browser
{
    public array $cookies = [];

    public function __construct(public readonly string $addr)
    {
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string, time: float} */
    public function request(string $method, string $path, array $headers = [], string|array|null $body = null): array
    {
        $ch = \curl_init("http://$this->addr$path");
        \curl_setopt_array($ch, $this->options($method, $headers, $body, $response));
        $start              = \microtime(true);
        $response['body']   = (string) \curl_exec($ch);
        $response['time']   = \microtime(true) - $start;
        $response['status'] = \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);

        return $response;
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, $headers);
    }

    public function json(string $path): mixed
    {
        return \json_decode($this->get($path)['body'], true);
    }

    /** Options for curl, with the cookies; $response gets the headers, and the jar the new cookies. */
    public function options(string $method, array $headers, string|array|null $body, ?array &$response): array
    {
        $response = ['headers' => []];
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . \implode('; ', \array_map(fn ($k, $v) => "$k=$v", \array_keys($this->cookies), $this->cookies));
        }
        $options = [
            \CURLOPT_CUSTOMREQUEST  => $method,
            \CURLOPT_NOBODY         => 'HEAD' === $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER     => $headers,
            \CURLOPT_FORBID_REUSE   => true,
            \CURLOPT_TIMEOUT        => 15,
            \CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$response) {
                if (\str_contains($line, ':')) {
                    [$name, $value]                                 = \explode(':', $line, 2);
                    $response['headers'][\strtolower($name)][]      = \trim($value);
                    if ('set-cookie' === \strtolower($name)) {
                        [$pair]              = \explode(';', \trim($value), 2);
                        [$cookie, $contents] = \explode('=', $pair, 2);
                        if (\str_contains(\strtolower($value), 'max-age=0') || '' === $contents) {
                            unset($this->cookies[$cookie]);
                        } else {
                            $this->cookies[$cookie] = $contents;
                        }
                    }
                }

                return \strlen($line);
            },
        ];
        if (null !== $body) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }

        return $options;
    }
}

/** Run requests at once, [browser, path] each; the responses in the same order. */
function overlapping(array $requests): array
{
    $multi   = \curl_multi_init();
    $handles = $responses = [];
    foreach ($requests as $i => [$browser, $path]) {
        $handles[$i] = \curl_init("http://{$browser->addr}$path");
        \curl_setopt_array($handles[$i], $browser->options('GET', [], null, $responses[$i]));
        \curl_multi_add_handle($multi, $handles[$i]);
    }
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.1);
    } while ($running > 0);
    foreach ($handles as $i => $ch) {
        $responses[$i]['status'] = \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $responses[$i]['body']   = \curl_multi_getcontent($ch);
        \curl_multi_remove_handle($multi, $ch);
    }

    return $responses;
}

/** A WebSocket client connection, after a successful handshake. */
function ws_connect(string $addr, string $path)
{
    $conn = \stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    \stream_set_timeout($conn, 5);
    $key = \base64_encode(\random_bytes(16));
    \fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!\str_contains($head, "\r\n\r\n") && false !== ($line = \fgets($conn))) {
        $head .= $line;
    }
    expect($head)->toStartWith('HTTP/1.1 101')
        ->and($head)->toContain('Sec-WebSocket-Accept: ' . \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));

    return $conn;
}

/** Send a text message, masked as a client must. */
function ws_send($conn, string $payload): void
{
    $mask = \random_bytes(4);
    \fwrite($conn, "\x81" . \chr(0x80 | \strlen($payload)) . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv(\strlen($payload), 4) + 1), 0, \strlen($payload))));
}

/** The next frame's opcode and (short) payload. */
function ws_read($conn): array
{
    $head    = \fread($conn, 2);
    $length  = \ord($head[1]) & 0x7F;
    $payload = '';
    while (\strlen($payload) < $length && '' !== ($chunk = (string) \fread($conn, $length - \strlen($payload)))) {
        $payload .= $chunk;
    }

    return [\ord($head[0]) & 0x0F, $payload];
}
