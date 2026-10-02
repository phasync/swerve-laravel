# Benchmarks

`run.sh <laravel-major>` builds the test application (the Laravel skeleton with the routes of
`tests/Fixtures/routes`), runs it as in production (`APP_ENV=production`, `APP_DEBUG=false`,
`php artisan optimize`, opcache on), and measures it with `wrk -t4 -c64 -d10s` under PHP-FPM 4
children behind nginx, then under swerve with 4 workers, without and with phasync-ext.

| Route | What it does |
|---|---|
| `/api/json` | returns an array as JSON; no session, no cookies |
| `/` | the skeleton's welcome page (Blade), with the `web` middleware: a new session in SQLite for every request, since wrk sends no cookie |
| `/counter` | increments a number in the session of a returning visitor (wrk sends its cookie) |

Sessions use the skeleton's database driver on SQLite, in WAL mode with a busy timeout.

[`results-compare.txt`](results-compare.txt): swerve (with and without phasync-ext) against
RoadRunner and FrankenPHP under Laravel Octane, 1, 2 and 4 workers, raw figures and the method in
its first lines. RoadRunner and FrankenPHP ran in a throw-away copy of the application with
`laravel/octane` installed, never in this package or its fixture; `/api/usleep?ms=10` is the wait
of a query, in `usleep()`. The machine is shared with other work, so figures vary by some 10%
between runs. `run.sh` repeats the PHP-FPM comparison; its results are not kept, since the
adapter changed from one application per worker to one per request.
