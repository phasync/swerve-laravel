# State a request can leave behind

Each request runs in an application of its own and the adapter resets it afterwards (see
[concurrency.md](concurrency.md)), but some state lives outside the application: in static
properties, in objects the pooled applications share, and in PHP itself. A request that changes
it can be seen by others. The isolation tests (`tests/IsolationTest.php`) pin what the adapter
guarantees; this page lists what they leave out, in three groups, and what to do instead.

## 1. Same on stock Octane

Octane's sandbox is a clone that shares the singletons and statics of the booted application, and
Laravel's own advice for it applies here: register things once at boot, never per request.
A request that changes one of these changes it for every request after it, in the worker.

- Registrations on shared objects: `View::share()` and view composers, `Gate::define()` and
  `before()`, `Auth::viaRequest()`, translator lines, cache and auth driver extensions,
  `Route` and `Request`/`Response` macros, exception `dontReport()`, trusted proxies and hosts,
  `Event::listen()` and model event listeners, `DB::listen()`, the query log, `Model::unguard()`,
  `Model::preventLazyLoading()` and the other `prevent*()`, global scopes.
- Static defaults: `Password::defaults()`, `File::defaults()`, `JsonResource::wrap()`,
  `Paginator::defaultView()`, `Number::useLocale()`, `Number::useCurrency()`, `Str::createUuidsUsing()`
  and the other `Str` factories, `Carbon::setTestNow()`, `Date::use*()`, `Sleep::fake()`,
  `Queue::createPayloadUsing()` (the adapter keeps a callback per application out of the list the
  providers append to, but not one your code adds).
- `putenv()` and `$_ENV`.

Instead: do it in a service provider's `boot()`, which runs once per application, or scope it to
the call (`Model::unguarded()`, `Carbon::withTestNow()`). A view's shared data and composers
belong in a provider too; data for one view goes through `view($name, $data)`.

## 2. Matters only with overlapping requests

These are switches that a request flips around a callback and flips back, so a request after it
never sees them. A request that waits inside the callback, though, lets another run while the
switch is on: `Model::unguarded()`, `Model::withoutEvents()`, `Model::withoutTouching()`,
`Relation::noConstraints()`, `Carbon::withTestNow()`, `Number::withLocale()`, `Sleep::fake()`.

Instead: keep the callback short and free of waiting (no query, HTTP call or `sleep()` inside it): do
the wait before or after it.

## 3. Process-level

PHP keeps these per process, and the adapter cannot give a request its own: `ini_set()`,
`setlocale()`, `date_default_timezone_set()`, `mt_srand()`, `header()` and `http_response_code()`
(the response is Laravel's, not PHP's), output buffers (with `Swerve::virtualize()` each request has its own;
without it a wait inside a view while it renders can mix output), `register_shutdown_function()`
(it runs when the worker ends, not when the request does), `exit()` and fatal errors (they end the
worker and every request in it; swerve starts another).

Instead: set `ini` values and the locale in `php.ini` or at boot; use `config('app.timezone')` and
`App::setLocale()` (per request) in place of the PHP functions; return a Laravel response instead
of calling `header()`; use `terminating()` or `dispatch()->afterResponse()` instead of shutdown
functions; throw an exception, or `abort()`, instead of `exit()`; and use `Random\Randomizer` or an
injected seed in place of `mt_srand()`.
