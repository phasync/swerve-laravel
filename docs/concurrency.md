# Concurrency

A worker serves many Laravel requests at once. Each runs in an application of its own, taken
from a pool of booted applications (`Handler`), so a request that waits (a coroutine sleeping,
phasync's HTTP client, and with phasync-ext `sleep()`, queries, curl and `flock()`) lets the
others run. With 4 workers and phasync-ext a route waiting 10 ms serves 1,661 requests/s
(README, "What it costs"); under Octane, with one application per worker, 295.

## Why one application per request

Laravel was written for one request at a time per process. Measured on Laravel 13.33 with
Octane 2.20, requests that overlap in one application, each setting something, waiting 0.1 s and
reading it back, took over each other's state. `tests/ConcurrencyTest.php` is that experiment,
and passes. Paths are under the application's `vendor/`: `Illuminate/` stands for
`laravel/framework/src/Illuminate/`, `Octane/` for `laravel/octane/src/`.

**The current application is process-wide.** `Container::$instance`
(`Illuminate/Container/Container.php:35`) and `Facade::$app`
(`Illuminate/Support/Facades/Facade.php:26`) point at one application: a request that waited
reached the last request's through `app()`, `request()`, `config()`, `url()`, `App::getLocale()`
and every facade.

**Octane's sandbox is a shallow clone.** `clone $app` copies the container's arrays, not the
services in them: sandboxes share the booted application's router, session store, auth guards,
translator, view factory, cookie jar, HTTP kernel and database connections, and Octane's
listeners reset those at the start of each request. The router's current route, the session and
its id, the guards, the locale, the view's shared data, the queued cookies and the open
transactions of the request still running were then those of the next. An application per
request shares none of it.

**Carbon's locale is process-wide**, as are PHP's output buffers (Octane's `ob_start()`, Blade
rendering a view into one): echoed output and views were cut and mixed between requests.

## What the adapter does

- A request takes an idle application from the pool, or a new one boots (8 ms and about 0.6 MiB;
  the first costs 130 ms and 17 MiB). A spare boots in the background when the last idle one is
  taken, and applications unused for 60 seconds are dropped. At most `maxApplications` (128)
  exist; requests above that wait for one to come free.
- Octane's listeners reset the application before the request, and the container goes back to
  what it held after boot once the request is over.
- Laravel's process-wide pointers (the container and facades, Eloquent's connection resolver and
  event dispatcher, the paginator's resolvers, `HandleExceptions`, Blade components' view
  factory, Carbon's translator) follow the request's coroutine: `Current` proxies them, or, when
  classes have registered context-local state (`phasync::$contextStateDefaults`) by the time the
  first application has booted, each application's own `phasync::$contextState` holds them.
- Output buffers belong to the process. With phasync-ext each request runs in `virtualize()`,
  which gives it its own; without it, a wait inside a view while it renders can mix the output
  of requests that overlap there, and stray output (`echo`, `dump()`) ends the worker.
- `Handler::run()`, for Laravel code in a WebSocket callback, runs in a fresh application of its own.

## What is not covered

State that other packages keep in static properties is shared by the requests that overlap in a
worker. `tests/StaticsTest.php` classifies every static property of `laravel/framework` and the
classes it runs on (`tests/statics/allowlist.php`), and fails on one that is new after an upgrade.
