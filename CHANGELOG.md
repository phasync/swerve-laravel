# Changelog

## Unreleased

- Tests: every static property of Laravel 13 and the classes it runs on is classified in `tests/statics/allowlist.php`, and
  `tests/StaticsTest.php` fails on one that is not (a new release added it) and on a row that has no property any more.
- Fix: without phasync-ext concurrent streams mixed their output (the callbacks' output buffers stack
  in the worker, so an echo reached the stream that started last). Stream callbacks now run one at a
  time per worker, the others waiting their turn; with phasync-ext they overlap as before (#5).
- Fix: output a route echoes outside its response (`echo`, `dump()`) is no longer captured around the
  kernel and put before the response's content: without phasync-ext output buffers are the worker's, so
  a request that waited handed its buffer to another. It goes to the worker's stdout; with phasync-ext
  it is dropped. A route that echoed and then waited made the request fail with phasync-ext
  ("ended without a response"); it now answers with its response.
- Fix: with context-local state a WebSocket callback kept losing what it set in
  `phasync::$contextState`: the request's end gave its context the defaults. It now keeps a copy
  of what it had (#3).
- Fix: `terminating()` callbacks registered while a request runs no longer pile up in the pooled
  application; the reset restores them to their post-boot list, as Octane's sandbox does (#4).
- Docs: streamed responses without phasync-ext, and a client that leaves mid-stream (#5).
- A worker always has an application ready: when a request takes the last idle one, a spare
  boots in the background (one at a time, not while the worker drains).
- Each pooled application has a `phasync::$contextState` array of its own, which the request that
  takes it runs with, so context-local static state belongs to the application. The handler picks
  this by itself: when context-local state is registered once the first application has booted,
  `Current` is not used and Laravel's pointers are the application's own; otherwise they are
  proxied as before, and state registered later fails the requests with a `LogicException`.
  Needs phasync 2.0.0-beta5 or later.
- Requests overlap in a worker without phasync-ext too, whenever one waits in a coroutine; they no
  longer take turns. Only `echo` and output buffers around a wait need phasync-ext.
- `new Handler($root, '/app')`: an application served in a folder that a proxy strips, with
  `url()`, redirects and signed URLs as under PHP-FPM.
- Requests run in pooled applications that Laravel Octane's listeners reset (`laravel/octane` is
  now required): an application serves one request at a time, a worker boots as many as it needs and drops those unused for 60 seconds, and
  the container goes back to its post-boot state after each request. A provider that reads the
  request while it boots finds an empty one. `app()` is an instance of the class
  `bootstrap/app.php` returned; a `final` subclass is refused at start.
- `Handler::run()` runs its closure in a fresh application, as `php artisan` bootstraps one.
- Laravel's process-wide pointers (`app()`, the facades, Eloquent's connection resolver and
  event dispatcher, ...) are proxies to the application of the request that runs. Callbacks
  that Laravel registers in static lists for every application (queue payloads, Artisan
  bootstrappers) are kept per application, and PHP's error and exception handlers are
  installed once, by the first application.
- A model that no provider boots, and whose `boot()` registers listeners, misses them in a
  request that uses it after another request already booted it (phasync-ext only).
- Requires phasync/swerve ^0.1.0-beta1 and symfony/psr-http-message-bridge (formerly pulled in
  by Octane). Use opcache (`opcache.enable_cli=1`): without it every bootstrap recompiles
  Laravel and the worker's memory grows.

## 0.1.0-alpha2

- WebSockets tested both ways from a route: echo, server push with `Swerve::subscribe()`
  across workers, clients leaving, identity, 250 open sockets per worker, drain, refusal.
- `Handler::run()`: Laravel code from a WebSocket callback, in a fresh application copy and the
  worker's turn. Outside a request, Laravel's services refer to a flushed copy and throw.
- A `ServerRequestInterface` route parameter is swerve's request for every request, not only
  upgrades: an ordinary `GET` to a WebSocket route is answered 426 (was 500).
- Requires phasync/swerve ^0.1.0-alpha15; CI uses phasync-ext 0.5.0-alpha10.

## 0.1.0-alpha1

- First release: Laravel 12 and 13 on swerve.
