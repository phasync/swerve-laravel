# swerve for Laravel

[![CI](https://github.com/phasync/swerve-laravel/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-laravel/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-laravel)](https://packagist.org/packages/phasync/swerve-laravel)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-laravel/php)](https://packagist.org/packages/phasync/swerve-laravel)
![License](https://img.shields.io/github/license/phasync/swerve-laravel)

**Your Laravel application, one request waiting while another runs.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a Laravel application unchanged, with each request in an application of its own, as under
PHP-FPM, and with phasync-ext requests that wait (a query, an API call, `sleep()`) overlap in
one worker.

```bash
composer config minimum-stability beta    # while swerve is in beta
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

**Take the user first.** The callback runs outside any request, and its application is gone: in
it `app()`, the facades and Eloquent throw. Read what the socket needs before
`WebSocket::from()`, and run Laravel code from the callback with `Handler::run()`:

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

`Handler::run()` runs its closure in a fresh application, bootstrapped as `php artisan` does,
and drops it afterwards. It returns what the closure returns and throws what it throws. The
application has no request, session or user.

An exception thrown by the callback closes the socket with 1011 and is written to swerve's log,
not to Laravel's exception handler.

## What it costs

Requests per second, the Laravel 13 skeleton in production mode (`php artisan optimize`), JIT
on, `wrk -t4 -c64`, best of three 10 s runs, one machine, with 4 workers. RoadRunner and
FrankenPHP run the same application under Laravel Octane, which keeps one application per
worker:

| 4 workers | swerve + phasync-ext | swerve | RoadRunner | FrankenPHP |
|---|---:|---:|---:|---:|
| `/api/json`, no session | 1,776 | 1,978 | 2,970 | 3,509 |
| `/json`, new session in SQLite | 779 | 845 | 1,248 | 1,364 |
| `/api/usleep?ms=10`, a 10 ms wait | **1,661** | 256 | 295 | 296 |

A request that computes costs more here than under Octane, which does not rebuild the
application: about 2.1 ms of CPU, 1.2 ms of it in building the application (providers 0.75).
A request that waits makes up for it with phasync-ext: the worker serves others meanwhile,
where an Octane worker sits idle (a 10 ms wait caps a worker at 100 requests/s), so what
the application waits for decides which is faster. Without phasync-ext a worker computes at about two thirds of an Octane
worker's rate, and waits as one does. [Method](benchmarks/).

## How it runs

Each request runs in an application of its own, taken from a pool of booted applications and reset
afterwards by [Laravel Octane](https://github.com/laravel/octane)'s listeners (the `listeners` of
`config/octane.php`, so yours apply too). A request never shares an application with another
that runs at the same time.

- **Per worker:** an application is booted on demand (`bootstrap/app.php` is required and the
  HTTP kernel bootstraps: config, providers, `boot()`), and the services Octane warms
  (`octane.warm`) are resolved. A worker boots as many as its overlapping requests
  need, and drops an application that sat unused for 60 seconds (the third argument of `Handler`).
  When a request takes the last idle application, a spare boots in the background (one at a
  time), so the next request finds one ready.
- **Context-local state:** `new Handler(__DIR__, '', 60.0, contextState: true)` gives each
  application a `phasync::$contextState` array of its own (phasync 2.0.0-beta5 or later). A request
  runs with the array of the application it takes, so static properties that are context-local
  state belong to the application, as its instance properties do. Laravel's pointers to the
  application are then the application's own, and the reset above applies on top.
- **Per request:** swerve's request becomes a Laravel request: headers, cookies, query, form
  fields, JSON, and uploads, which stay swerve's temporary files (no copy; deleted after the
  request unless moved). Octane's `RequestReceived` listeners reset the application (session,
  auth, cookies, locale, ...), the HTTP kernel handles the request and the response goes to
  swerve. After that `terminate()` runs, with the `defer()` callbacks, and the container goes
  back to what it held after boot: whatever the request resolved or registered is dropped. An
  application whose request failed or was cancelled is dropped instead of reused. `app()` is an
  instance of the class `bootstrap/app.php` returned (`Application`, or your subclass of it);
  a `final` subclass is refused at start.
- **No request at boot:** a provider that reads the request while it boots finds an empty one
  for `/`, as under Octane. Do that work in a middleware or a route.
- **Concurrency:** Laravel keeps "the" application in process-wide pointers (`app()`, the
  facades, Eloquent's connection resolver and event dispatcher, ...). They are proxies that
  forward to the application of the request that runs, so requests that wait overlap in one
  worker, each in its own application. A request waits in a coroutine
  (`phasync::sleep()`, phasync's HTTP client, sockets) with or without phasync-ext; with it
  `sleep()`, `usleep()`, PDO/mysqli queries, curl and `flock()` wait that way as well, and
  without it they hold the worker, as under any other server. One thing needs phasync-ext:
  Laravel's view engine buffers output (`ob_start()` in `PhpEngine`, and in `@section`,
  `@component` and `@push`), and PHP's output buffers belong to the process. A wait inside a
  view while it renders, such as a lazily loaded relation, mixes the output of requests that
  overlap there, unless phasync-ext's `virtualize()` keeps each request's buffers apart.
- **Sessions:** Laravel's own drivers (database, file, cookie, Redis), unchanged.
- **Streaming:** `response()->stream()`, `response()->eventStream()` and downloads go out as the
  callback echoes; `HEAD` requests don't run the callback. A client that leaves cancels the
  callback where it next waits.
- **WebSockets:** see [WebSockets](#websockets). The connection is swerve's; only the
  handshake is a Laravel request.

## Before you deploy

- **Use phasync-ext for concurrency.** Without it a worker runs one Laravel request at a time
  (size `--workers` like PHP-FPM's `pm.max_children`), and a request that waits holds up the
  others queued in its worker. With it they overlap, each in an application of its own:
  memory is that of one application per request in flight.
- **Behind a proxy that serves the application in a folder** (`https://example.com/app/`
  forwarded to swerve as `/`), use `new Handler(__DIR__, '/app')`. `url()`, `route()`,
  `back()` and signed URLs then include the folder, as under PHP-FPM.
- **Run swerve with opcache** (`php -d opcache.enable_cli=1 vendor/bin/swerve ...`, or in the
  CLI's `php.ini`): without it every application recompiles Laravel's files, which is slow and
  makes the worker's memory grow.
- **An application is reused,** as under Octane: state your own code keeps in a singleton or a
  static between requests is shared by the requests that run in that application one after
  the other. Octane's `config/octane.php` `listeners` and `flush` are the place to reset it.

## Compatibility

| Laravel | PHP | phasync-ext |
|---|---|---|
| 12 | 8.2 – 8.5 | optional; tested with and without |
| 13 | 8.3 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
