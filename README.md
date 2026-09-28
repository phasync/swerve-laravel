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

## What changes

Requests per second, the Laravel 13 skeleton in production mode (`php artisan optimize`), 4
PHP-FPM children behind nginx against 4 swerve workers, same machine, opcache on:

| Laravel 13 skeleton, 4 workers | PHP-FPM | swerve | | swerve + phasync-ext |
|---|---:|---:|---:|---:|
| JSON route, no session | 1,545 | 2,882 | 1.9× | 2,907 |
| Welcome page, new session | 146 | 1,150 | 7.9× | 1,140 |
| Session counter, returning visitor | 119 | 1,217 | 10.2× | 1,190 |

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
- **WebSockets:** in a route for an upgrade request, a `ServerRequestInterface` parameter is
  swerve's own request, and the route may return `Swerve\Http\WebSocket::from($request, ...)`:

  ```php
  Route::get('/ws', fn (ServerRequestInterface $request) => WebSocket::from($request, function (WebSocket $ws) {
      foreach ($ws as $message) {
          $ws->send("echo: $message");
      }
  }));
  ```

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
- **A WebSocket callback runs after its request ended**, outside the turn: take what it needs
  from the request (the user, the session) before `WebSocket::from()`, since `auth()`,
  `session()` and the facades there see whichever request runs at the time.
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
