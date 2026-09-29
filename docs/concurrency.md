# Concurrency

`Handler::handle()` runs each Laravel request inside `phasync\Util\Synchronized::run()`, and so
does `Handler::run()` for Laravel code in a WebSocket callback: a worker runs one Laravel request
at a time. Waiting requests are suspended, so the worker keeps accepting connections, reading
bodies, writing responses and serving static files and WebSockets meanwhile, but a request that
waits for the database holds the worker. On one worker with phasync-ext, a route waiting 10 ms
serves 70 requests/s (`wrk -t2 -c32`); a route that doesn't wait, 664. Size workers for the
requests you expect to be waiting at once.

## Why

Measured on Laravel 13.33 with Octane 2.20. Paths are under the application's `vendor/`:
`Illuminate/` stands for `laravel/framework/src/Illuminate/`, `Octane/` for `laravel/octane/src/`. Each
item is checked by `tests/ConcurrencyTest.php`, whose requests overlap in one worker: each sets
something, waits 0.1 s and reads it back. With the lock removed (`$this->worker->handle(...)`
called directly in `Handler::handle()`), every item below fails, without phasync-ext, with it,
and with the request run in phasync-ext's `virtualize()` (`Swerve\Http\Virtual::run()`), except
where noted. Only the query string read from the injected `Request` stays correct.

**The current application is process-wide.** Octane's worker makes each request's sandbox the
application (`Octane/CurrentApplication.php:16-22`, called at `Octane/Worker.php:75`) by setting
`Container::$instance` (`Illuminate/Container/Container.php:35`) and `Facade::$app`
(`Illuminate/Support/Facades/Facade.php:26`), and sets them back to the booted application when a
request ends (`Octane/Worker.php:118`). A request that waited then reaches the last request's
sandbox, or the booted one, through `app()`, `request()`, `config()`, `url()`, `App::getLocale()`
and every facade: it read the other request's query string, route parameter, container
instances, scoped services and configuration, or nothing.

**The sandbox is a shallow clone.** `clone $app` copies the container's arrays, not the services
in them: sandboxes share the booted application's router, session store, auth guards, translator,
view factory, cookie jar, HTTP kernel and database connections. Octane's listeners reset these at
the start of each request, which is what breaks the request still running:

- the router's current route and the route's parameters (`Illuminate/Routing/Router.php:777`,
  `Illuminate/Routing/Route.php:392`): `$request->route('tag')` on the injected request is the other one's;
- one session `Store` per driver (`Illuminate/Support/Manager.php:82`), given the next request's
  session id (`Illuminate/Session/Middleware/StartSession.php:159`) and flushed
  (`Octane/Listeners/FlushSessionState.php:20`): a request read, and saved under its
  own cookie, the other request's session;
- the auth guards, forgotten (`Octane/Listeners/FlushAuthenticationState.php:21`): `Auth::user()` and
  `$request->user()` were the other request's user;
- the translator's locale (`Illuminate/Translation/Translator.php:33`), the view's shared data
  (`Illuminate/View/Factory.php:58`) and the queued cookies (`Illuminate/Cookie/CookieJar.php:48`, flushed by
  `Octane/Listeners/FlushQueuedCookies.php:18`): every response got the last request's cookie;
- the database connections (`Illuminate/Database/DatabaseManager.php:47`): a write made while another
  request's transaction was open went into it (`transactionLevel()` 1), and the HTTP kernel,
  pointed at the next sandbox (`Octane/Listeners/GiveNewApplicationInstanceToHttpKernel.php:16`), failed
  the first request once that sandbox was flushed (`BindingResolutionException`).

**Carbon's locale is process-wide.** Laravel sets it on each request and on `App::setLocale()`
(`nesbot/carbon/src/Carbon/Laravel/ServiceProvider.php:80`): dates were formatted in the last
request's language.

**Output buffers are process-wide.** Octane collects what a request echoes with `ob_start()`
(`Octane/Worker.php:82`), and Blade renders views into one (`Illuminate/View/Engines/PhpEngine.php:51`): echoed
output and views were cut and mixed between requests. `virtualize()` gives each request its own
output buffers, and removes this item; it removes none of the others.

`Handler::run()` takes the same turn because its task runs in a sandbox of the same booted
application: Octane's listeners for `TaskReceived` point the shared services at it, and it is
flushed when the task ends. Without the lock, a `Handler::run()` from a WebSocket callback while
another request waited made that request fail (`Target class [events] does not exist`), without
and with phasync-ext (the identity test in `tests/WebSocketTest.php`).

## What was tried

- **A narrower lock.** The state above is read throughout the request: every facade and helper,
  the middleware, the session saved after the response. There is no smaller part to lock.
- **Resetting state per request.** That is what Octane's listeners do, and the reset at the start
  of one request is what breaks the others in flight (the session flushed, the guards forgotten).
- **`virtualize()`.** Removes the output buffers only (see above).
- **A pool of applications**, one per overlapping request, as swerve-symfony pools its kernels:
  it works, on the branch `concurrent`. Each application serves one request at a time in Octane's
  sandbox; the process-wide pointers (the container and facades, Eloquent's connection resolver
  and event dispatcher, the paginator's resolvers, `HandleExceptions`, Blade components' view
  factory, Carbon's translator) are replaced by proxies that follow the request's phasync context,
  and requests run in `virtualize()`, without which the pool keeps one application. An application
  boots in 8 ms and takes about 0.6 MiB (the first, 130 ms and 17 MiB). With phasync-ext, one
  worker: the 10 ms route went from 70 to 664 requests/s, the route that doesn't wait from 664 to
  681, the worker from 48 to 58 MiB; the whole suite and `tests/ConcurrencyTest.php` pass without
  and with phasync-ext. Process-wide state kept by other packages is not covered by these proxies.
