# Changelog

## Unreleased

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
