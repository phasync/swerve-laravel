# swerve for Laravel

[![CI](https://github.com/phasync/swerve-laravel/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-laravel/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-laravel)](https://packagist.org/packages/phasync/swerve-laravel)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-laravel/php)](https://packagist.org/packages/phasync/swerve-laravel)
![License](https://img.shields.io/github/license/phasync/swerve-laravel)

**Your Laravel application, many requests at once.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a Laravel application unchanged. It is concurrent by design: a worker serves many requests
at once, each in a pooled application of its own, so a request that waits (an API call, a
coroutine sleeping, and with ext-phasync `sleep()` and queries) does not hold the others.

The framework running correctly on swerve does not mean your application does: static
properties and other globals in your own code are not reset between requests — the pool resets
Laravel's own state, not yours ([what it doesn't cover](docs/shared-state.md)). See [Making your
application safe for concurrent
requests](https://phasync.github.io/guides/concurrent-requests-in-your-application/).
[phasync/doctor](https://github.com/phasync/doctor) lists the candidates in your code:
`composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`.

It requires [Laravel Octane](https://github.com/laravel/octane) (`laravel/octane` ^2.0, installed
with the package) for the listeners that reset an application between requests; Octane's own
servers and `octane:install` are not used. ext-phasync is optional.

```bash
composer require phasync/swerve-laravel
```

```php
<?php
// swerve.php
require __DIR__ . '/vendor/autoload.php';
return Swerve\Laravel\serve(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080
```

That's the whole setup, apart from [a reverse proxy's folder](#before-you-deploy).
`public/index.php` stays as it is, so the same application still runs under PHP-FPM; `serve()`
pools applications so overlapping requests each borrow one of their own
(`AsyncMode`, see [MODES.md](https://github.com/phasync/swerve/blob/main/MODES.md)). `swerve.php`
requires the autoloader itself: nothing else has loaded it yet when swerve evaluates this file.

A `PersistentMode` application may instead give `PersistentMode::serve()` a bare application (or
its `Illuminate\Contracts\Http\Kernel`) it booted itself, one request at a time, with no pool:

```php
<?php
// swerve.php
require __DIR__ . '/vendor/autoload.php';
return Swerve\PersistentMode::serve(__DIR__ . '/public', require __DIR__ . '/bootstrap/app.php');
```

`AsyncMode` refuses a bare application or kernel at startup (it would overlap requests on it): use
`serve()`, which pools applications, there instead.

## WebSockets

A route returns `Swerve\Laravel\WebSocketResponse::from()`; its callback gets swerve's
`Swerve\WebSocket`. The handshake is an ordinary Laravel request (middleware, session,
`$request->user()`); the callback runs after it, for as long as the connection is open, and holds
no application: the worker goes on serving requests meanwhile (tested with 250 open sockets in one
worker).

```php
use Illuminate\Http\Request;
use Swerve\Laravel\WebSocketResponse;
use Swerve\WebSocket;

Route::get('/echo', fn (Request $request) => WebSocketResponse::from($request, function (WebSocket $ws) {
    foreach ($ws as $message) {             // ends when the client leaves
        $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
    }
}));
```

An ordinary `GET` to that route is answered `426 Upgrade Required`. `from()` also takes
`subprotocols`, an `origins` allow-list and `maxMessage`, as swerve's `WebSocket::from()` does.

**Server push.** A socket that only forwards a topic, and any route, command or job that
publishes to it. Messages reach the subscribers in every worker; the callback ends when its
client leaves, or with a close frame (1001) when the worker drains.

```php
use Swerve\Swerve;

Route::get('/news', fn (Request $request) => WebSocketResponse::from($request, function (WebSocket $ws) {
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
`WebSocketResponse::from()`, and run Laravel code from the callback with `Handler::run()`:

```php
use Swerve\Laravel\Handler;

Route::get('/chat', function (Request $request) {
    $user = $request->user();               // now, while this request runs

    return WebSocketResponse::from($request, function (WebSocket $ws) use ($user) {
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

## How it runs

This is `serve()`'s pooled applications (`AsyncMode`). A bare application or kernel in
`PersistentMode` is reset and `terminate()`d the same way, per request, but with no pool: one
application, booted by yours. Either way, `Swerve\Laravel\LaravelAdapter` (registered once, from
this package's Composer autoload `files`, see `integrate-with-swerve.php`) is what swerve calls.

Each request runs in an application of its own, taken from a pool of booted applications and reset
afterwards by [Laravel Octane](https://github.com/laravel/octane)'s listeners (the `listeners` of
`config/octane.php`, so yours apply too). A request never shares an application with another
that runs at the same time.

- **Per worker:** an application is booted on demand (`bootstrap/app.php` is required and the
  HTTP kernel bootstraps: config, providers, `boot()`), and the services Octane warms
  (`octane.warm`) are resolved. A worker boots as many as its overlapping requests
  need, and no spare. It keeps as many as were in use at once during the last 60 seconds (the
  third argument of `Handler`, `$window`) and drops the rest when the pool is next used.
  The fourth, `maxApplications` (128), caps them: it is back-pressure, requests above it wait for
  an application to come free. The applications are a `phasync\Util\Pool`.
- **Per-application state:** each application has an `AppState::$state` array of its own, which
  the request that takes it runs with: a `phasync::onScopeChange()` hook binds it by the request's
  `Scope` whenever one of its coroutines runs. When slots were added to `AppState::$defaults` by
  the time the first application has booted, Laravel's pointers to the application are left to
  that state, as the application's own, and the reset above applies on top. Otherwise the
  process-wide pointers are proxied ([docs/concurrency.md](docs/concurrency.md); what a request can
  leave behind for the next, and what to do instead: [docs/shared-state.md](docs/shared-state.md)).
  Adding a slot after that point fails every later request with a `LogicException`. A WebSocket
  callback keeps a copy of the state its request left it.
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
  (`phasync::sleep()`, phasync's HTTP client, sockets) with or without ext-phasync; with it
  `sleep()`, `usleep()`, PDO/mysqli queries, curl and `flock()` wait that way as well, and
  without it they hold the worker, as under any other server. Laravel's view engine buffers
  output (`ob_start()` in `PhpEngine`, and in `@section`, `@component` and `@push`), and PHP's
  output buffers belong to the process: **a wait inside a view while it renders**, such as a
  lazily loaded relation with ext-phasync, **mixes the output of requests that overlap there**
  ([#15](https://github.com/phasync/swerve-laravel/issues/15)). Load what a view needs before it
  renders (eager loading) until that is fixed.
- **Output outside the response:** `echo`, `print` and `dump()` in a route are not part of its
  response: return a response (`response()`, a view) instead. A stream callback's echoed output
  is part of its response (see Streaming).
- **Sessions:** Laravel's own drivers (database, file, cookie, Redis), unchanged.
- **Streaming:** `response()->stream()`, `response()->eventStream()` and downloads go out as the
  callback echoes; `HEAD` requests don't run the callback. A client that leaves cancels the
  callback where it next waits: its `finally` blocks run, but the application is dropped
  instead of reset, and `terminate()` callbacks do not run for that request. A stream callback's
  output is collected in a buffer that belongs to the process, so stream callbacks that `echo`
  run one at a time in a worker, the others waiting their turn (a stream that never ends holds
  the turn; other requests are not held up). A callback that `yield`s its chunks
  (`response()->stream(function () { yield ...; })`) needs no buffer and overlaps.
- **WebSockets:** see [WebSockets](#websockets). The connection is swerve's; only the
  handshake is a Laravel request.

## Before you deploy

- **Memory is one application per request in flight** (about 0.6 MiB each beyond the first).
  Requests overlap while they wait in a coroutine; without ext-phasync `sleep()`, `usleep()`,
  database queries and curl hold the worker, so size `--workers` like PHP-FPM's `pm.max_children`
  for an application that waits on those. ext-phasync makes them yield.
- **Behind a proxy that serves the application in a folder** (`https://example.com/app/`
  forwarded to swerve as `/`), use `Swerve\Laravel\serve(__DIR__, basePath: '/app')` in a
  `swerve.php` (see the setup above). `url()`, `route()`,
  `back()` and signed URLs then include the folder, as under PHP-FPM. `X-Forwarded-Prefix`
  does nothing for an application that doesn't trust it.
- **Run swerve with opcache** (`php -d opcache.enable_cli=1 vendor/bin/swerve ...`, or in the
  CLI's `php.ini`): without it every application recompiles Laravel's files, which is slow and
  makes the worker's memory grow.
- **`exit`, `die()` and `dd()` end the worker**, and the requests it serves with it.
- **A stop ends the requests in flight.** On a shutdown or reload swerve stops a worker at once:
  a request still waiting then ends, answered `503` by swerve when nothing was sent yet (Laravel
  neither reports nor renders it); clients retry. `defer()` work that waits is cut short too.
- **A validation closure that waits holds the worker with ext-phasync** (a query in a closure
  rule: Laravel calls it through `Closure::__invoke()`,
  [ext-phasync-src#106](https://github.com/phasync/ext-phasync-src/issues/106)). A rule class
  is not affected.
- **Open WebSockets count as connections:** without ext-phasync a worker holds about 960; see
  swerve's [sizing](https://github.com/phasync/swerve/blob/main/docs/production.md#sizing).
- **Multipart `PUT` and `PATCH` bodies are not parsed**; url-encoded and JSON ones are. Send
  forms with files as `POST` with `_method=PUT`.
- **Code and config changes need a reload:** `--watch` during development, `SIGHUP` (a rolling
  reload) in production. `php artisan optimize` works as usual.
- **SQLite:** use WAL mode and a busy timeout (`'journal_mode' => 'wal'`, `'busy_timeout' => 5000`
  in `config/database.php`). With Laravel's defaults, concurrent session writes time out under load.
- **An application is reused,** as under Octane: state your own code keeps in a singleton or a
  static between requests is shared by the requests that run in that application one after
  the other. Octane's `config/octane.php` `listeners` and `flush` are the place to reset it.

## Compatibility

| Laravel | PHP | ext-phasync |
|---|---|---|
| 12 | 8.2 – 8.5 | optional; tested with and without |
| 13 | 8.3 – 8.5 | optional; tested with and without |

The suite runs on each row without and with ext-phasync. The classification of Laravel's static
properties (`tests/statics/allowlist.php`) is that of Laravel 13; its test is skipped on 12.

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
