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

[`results-13.txt`](results-13.txt): Laravel 13, raw wrk output, with the machine and versions in
its first lines. The machine is shared with other work, so figures vary by some 10% between runs.
