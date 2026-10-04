# Changelog

## 0.1.0-alpha4 (2026-10-04)

- Fix: what a request registered in its application's container (a singleton, `resolving()`,
  `extend()`, a contextual binding, an alias, a tag, a service provider registered at runtime)
  reached the requests after it. The application's properties go back to what they held after boot (#8).
- Fix: `Mail::fake()`, `Event::fake()`, `Queue::fake()` and every other `Facade::swap()` outlived the request
  and applied to the others, as `Facade::$resolvedInstance` is process-wide. Each request has its own (#9).
- Fix: Octane's `GiveNewRequestInstanceToPaginator` pointed the paginator's resolvers at the application of the
  request that ran it, so a concurrent request paginated with another's. The listener is a no-op (#10).
- Fix: the view provider's terminating callback emptied the Blade components' view factory, which the next
  component then cached, from the application of whichever request met it first. Put back after terminating (#11).
- `docs/shared-state.md`: what a request can leave behind for the next that the adapter does not undo, in three
  groups (same on stock Octane, only with overlapping requests, process-level), and what to do instead.
- Tests: `tests/IsolationTest.php`, a matrix of per-request state (session, auth, CSRF, cookies, locale, config,
  URL, validation, route model binding, terminable middleware, container registrations, facade fakes, the
  paginator and components, concurrent queries on MariaDB when it is reachable) with the overlapping requests
  and the requests after them.
- The applications are a `phasync\Util\Pool`, which replaces the handler's own pool. New third
  argument `window` (60 seconds) and fourth `maxApplications` (128): at most that many exist,
  requests above it wait; the pool keeps as many applications as were in use at once within the
  window and drops the rest the next time it is used. One that failed is flushed and dropped.
  No spare is booted: every application after the first is booted for the request that needs it.
- Fix: starting on Laravel 12 failed (`Model::$booting` is Laravel 13's).
- Concurrent by design: a worker serves many requests at once, each in a pooled application of
  its own (it replaces the one-request-at-a-time worker of 0.1.0-alpha2). `docs/concurrency.md`
  says what Laravel shares between requests and what the adapter does about it.
- Tests: every static property of Laravel 13 and the classes it runs on is classified in `tests/statics/allowlist.php`, and
  `tests/StaticsTest.php` fails on one that is not (a new release added it) and on a row that has no property any more.
- Fix: without phasync-ext concurrent streams mixed their output (the callbacks' output buffers stack
  in the worker, so an echo reached the stream that started last). Stream callbacks now run one at a
  time per worker, the others waiting their turn; with phasync-ext they overlap as before (#5).
- Stray output (`echo`, `print`, `dump()` outside the response) is not supported without phasync-ext:
  swerve ends the worker with exit status 4 and a message naming the request and the route's
  line, and starts a new one. With `Swerve::virtualize()` it is the response. The handler no longer captures it
  around the kernel (the earlier capture put it before the response's content), and a route that
  echoed and then waited now answers with its response.
- Virtualization is swerve's: `Swerve::virtualize()` in `swerve.php` makes each request a virtualize() of its own,
  and the handler no longer sets one up (no extra coroutine per request). Output a route echoes is the response,
  as under PHP-FPM; a streamed response's callback echoes straight to the client, with no pipe.
- A `response()->stream()` callback that is a generator is read by the handler, which writes the chunks it
  yields to the response: no output buffer, so such streams overlap without phasync-ext and take no turn
  (`LARAVEL_OCTANE` is set, as under Octane, so that Laravel hands the generator over as it is).
  Streams whose callback echoes (and `eventStream()`) are unchanged.
- Tests: the skeleton's routes leave the worker's pid unchanged, and with `Swerve::virtualize()` an echo in a route is
  harmless; the guard itself is swerve's, with tests there.
- Depends on the development versions of phasync/phasync and phasync/swerve (`dev-main`) until
  their next tags; install with `minimum-stability` dev and `prefer-stable`.
- Fix: with context-local state a WebSocket callback kept losing what it set in
  `phasync::$contextState`: the request's end gave its context the defaults. It now keeps a copy
  of what it had (#3).
- Fix: `terminating()` callbacks registered while a request runs no longer pile up in the pooled
  application; the reset restores them to their post-boot list, as Octane's sandbox does (#4).
- Docs: streamed responses without phasync-ext, and a client that leaves mid-stream (#5).
- Each pooled application has a `phasync::$contextState` array of its own, which the request that
  takes it runs with, so context-local static state belongs to the application. The handler picks
  this by itself: when context-local state is registered once the first application has booted,
  `Current` is not used and Laravel's pointers are the application's own; otherwise they are
  proxied as before, and state registered later fails the requests with a `LogicException`.
- Requests overlap in a worker without phasync-ext too, whenever one waits in a coroutine; they no
  longer take turns. Only output buffers around a wait need phasync-ext.
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
- Requires symfony/psr-http-message-bridge (formerly pulled in
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
