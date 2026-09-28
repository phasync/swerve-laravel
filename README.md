# swerve for Laravel

[![CI](https://github.com/phasync/swerve-laravel/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-laravel/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-laravel)](https://packagist.org/packages/phasync/swerve-laravel)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-laravel/php)](https://packagist.org/packages/phasync/swerve-laravel)
![License](https://img.shields.io/github/license/phasync/swerve-laravel)

**Your Laravel application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a Laravel application unchanged.

```bash
composer config minimum-stability alpha   # while swerve is in alpha
composer config prefer-stable true         # everything else stays stable
composer require phasync/swerve-laravel
```

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Laravel\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

That's the whole setup. `public/index.php` stays as it is, so the same application still runs
under PHP-FPM.

## WebSockets

A route returns `Swerve\Http\WebSocket::from()` for a `ServerRequestInterface` parameter,
which is swerve's own request. The handshake is an ordinary Laravel request (middleware,
session, `$request->user()`); the callback runs after it, for as long as the connection is
open, and holds no Laravel turn: the worker goes on serving requests meanwhile (tested with 250
open sockets in one worker).

```php
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;

Route::get('/echo', fn (ServerRequestInterface $request) => WebSocket::from($request, function (WebSocket $ws) {
    foreach ($ws as $message) {             // ends when the client leaves
        $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
    }
}));
```

An ordinary `GET` to that route is answered `426 Upgrade Required`.

**Server push.** A socket that only forwards a topic, and any route, command or job that
publishes to it. Messages reach the subscribers in every worker; the callback ends when its
client leaves, or with a close frame (1001) when the worker drains.

```php
use Swerve\Swerve;

Route::get('/news', fn (ServerRequestInterface $request) => WebSocket::from($request, function (WebSocket $ws) {
    foreach (Swerve::subscribe('news') as $message) {
        $ws->send($message);
    }
}));

Route::post('/news', function (Request $request) {
    Swerve::publish('news', json_encode($request->validate(['text' => 'required|string'])));

    return response()->noContent();
});
```

Every subscriber sees a topic's messages in the same order. Messages published by one request
arrive in the order published; two requests in different workers may publish in the other
order, so give messages that carry state a version from your database.

**Take the user first.** The callback runs outside any request. Laravel keeps the current
request, session, user and services in process-wide state, so inside the callback `auth()`,
`session()`, `request()` and the facades see whichever request the worker runs at that moment,
or, between requests, the application copy of the last one, which is gone: there the database,
Eloquent and `Auth` throw. Read what the socket needs before `WebSocket::from()`, and run
Laravel code from the callback with `Handler::run()`:

```php
use Swerve\Laravel\Handler;

Route::get('/chat', function (Request $request, ServerRequestInterface $psr) {
    $user = $request->user();               // now, while this request runs

    return WebSocket::from($psr, function (WebSocket $ws) use ($user) {
        foreach ($ws as $text) {
            $message = Handler::run(fn () => $user->messages()->create(['text' => $text]));
            Swerve::publish('chat', $message->toJson());
        }
    });
})->middleware('auth');
```

`Handler::run()` runs its closure as Octane runs a task: in a fresh copy of the application,
with Octane's listeners, and in the worker's turn, so it waits while a request runs. It returns
what the closure returns and throws what it throws. The copy has no request, session or user;
not callable from inside a request, which holds the turn already.

An exception thrown by the callback closes the socket with 1011 and is written to swerve's log,
not to Laravel's exception handler.

## What changes

Requests per second, the Laravel 13 skeleton in production mode (`php artisan optimize`), 4
PHP-FPM children behind nginx against 4 swerve workers, same machine, opcache on:

| Laravel 13 skeleton, 4 workers | PHP-FPM | swerve | | swerve + phasync-ext |
|---|---:|---:|---:|---:|
| JSON route, no session | 1,558 | 2,900 | 1.9× | 2,854 |
| Welcome page, new session | 164 | 1,162 | 7.1× | 1,132 |
| Session counter, returning visitor | 141 | 1,191 | 8.5× | 1,205 |

PHP-FPM builds the services the `web` middleware needs (session, cookies, encryption, views,
the database connection) for every request; a swerve worker keeps them. [Method and raw
results](benchmarks/).

## How it runs

The package is a server for [Laravel Octane](https://laravel.com/docs/octane): Octane's own
`Worker` runs the application, and swerve serves it. Octane's Swoole, RoadRunner and
FrankenPHP servers are not used.

- **Once per worker:** Octane boots `bootstrap/app.php` (the HTTP kernel's bootstrappers, the
  deferred providers, the services in `octane.warm`) and dispatches `WorkerStarting`.
- **Per request:** swerve's request becomes a Laravel request: headers, cookies, query, form
  fields, JSON, and uploads, which stay swerve's temporary files (no copy; deleted after the
  request unless moved). Then Octane clones the booted application into a sandbox, dispatches
  `RequestReceived`, runs the HTTP kernel, and the response goes to swerve. After that come
  `RequestHandled`, `terminate()` with the `defer()` callbacks, `RequestTerminated` and
  `OperationTerminated`, and the sandbox is flushed. Octane's listeners reset what Laravel
  keeps between requests (auth guards, the session store, queued cookies, the config and URL
  generator sandboxes, ...), and so do the Octane listeners of packages such as Livewire,
  Inertia and Sentry. Add your own in `config/octane.php` (`php artisan vendor:publish
  --tag=octane-config`).
- **Concurrency:** one Laravel request at a time per worker. Laravel keeps the current request,
  session, user and container in process-wide globals, so two requests in one worker would take
  over each other's session and login. Requests waiting for their turn are suspended
  (`phasync\Util\Synchronized`): meanwhile the worker goes on accepting connections, reading
  request bodies, writing responses and serving static files and WebSockets.
- **Sessions:** Laravel's own drivers (database, file, cookie, Redis), unchanged.
- **Streaming:** `response()->stream()`, `response()->eventStream()` and downloads go out as the
  callback echoes; `HEAD` requests don't run the callback. A client that leaves cancels the
  callback where it next waits.
- **WebSockets:** see [WebSockets](#websockets). The connection is swerve's; only the
  handshake is a Laravel request.

## Before you deploy

- **Size `--workers` like PHP-FPM's `pm.max_children`.** A worker runs one Laravel request at a
  time, so a request that waits (a slow query, an API call) holds up the Laravel requests queued
  in its worker; phasync-ext doesn't change that. The kernel hands connections to workers
  without regard to how busy they are, so a slow request can delay requests that another
  worker would have served at once.
- **A streamed response holds its worker's turn** until the callback returns: an endless
  Server-Sent Events loop is one worker per client. For many clients, use a WebSocket, or
  swerve's [publish and subscribe](https://github.com/phasync/swerve#publish-and-subscribe).
  When a client leaves, the callback is cancelled at its next wait, which needs phasync-ext
  for `sleep()` (or `phasync::sleep()` without it); a callback that never waits runs on.
- **A WebSocket callback runs outside any request:** take the user and anything else it needs
  from the request before `WebSocket::from()`, and wrap Laravel code in the callback (queries,
  Eloquent, logging, dispatching jobs) in `Handler::run()`. See [WebSockets](#websockets).
- **Open WebSockets count as connections:** without phasync-ext a worker holds about 960; see
  swerve's [sizing](https://github.com/phasync/swerve/blob/main/docs/production.md#sizing).
- **Work after the response holds the worker's turn:** `defer()` callbacks and terminable
  middleware run before the next Laravel request of that worker, so keep them short or queue
  them. A draining worker (a reload, a shutdown) waits for them, up to `--grace`.
- **`exit`, `die()` and `dd()` end the worker**, and the requests it serves with it. Use
  `dump()`.
- **State in static properties and singletons lives on** from request to request, as under
  Octane: register per-request services with `$app->scoped()`, or list them in `octane.flush`.
- **Multipart `PUT` and `PATCH` bodies are not parsed**; url-encoded and JSON ones are. Send
  forms with files as `POST` with `_method=PUT`.
- **Code and config changes need a reload:** `--watch` during development, `SIGHUP` (a rolling
  reload) in production. `php artisan optimize` works as usual.
- **SQLite:** use WAL mode and a busy timeout (`'journal_mode' => 'wal', 'busy_timeout' => 5000`
  in `config/database.php`). With Laravel's defaults, concurrent session writes time out under
  load.

## Compatibility

| Laravel | PHP | phasync-ext |
|---|---|---|
| 12 | 8.2 – 8.5 | optional; tested with and without |
| 13 | 8.3 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
