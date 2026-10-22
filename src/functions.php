<?php

namespace Swerve\Laravel;

use Swerve\AsyncMode;
use Swerve\Mode;

/**
 * Runs a Laravel application on swerve: pooled applications, safe for overlapping requests, each
 * request borrowing one of its own (see {@see Handler}). `public/index.php` stays as it is, so
 * the same application still runs under PHP-FPM.
 *
 * `swerve.php` needs nothing of its own but this, since nothing has loaded the application's
 * autoloader yet when it runs:
 *
 * ```php
 * <?php
 * require __DIR__ . '/vendor/autoload.php';
 * return Swerve\Laravel\serve(__DIR__);
 * ```
 *
 * A `PersistentMode` application may instead give `PersistentMode::serve()` a bare application
 * or `Illuminate\Contracts\Http\Kernel` it booted itself: {@see LaravelAdapter} runs it directly,
 * one request at a time, with no pool.
 *
 * @param string $appDir          the application directory: where composer.json is
 * @param string $basePath        the folder the application is served under when a reverse proxy
 *                                strips it before swerve sees the request, such as '/demos/app';
 *                                url(), redirects and signed URLs then include it, as under PHP-FPM
 * @param float  $window          seconds to look back for the most applications that were in use
 *                                at once: a worker keeps that many and drops the rest, so it holds
 *                                what a burst needed for that long
 * @param int    $maxApplications the most applications a worker has at once, about 0.5 MiB each
 *                                while a request uses it; requests beyond that wait for one to
 *                                come free
 */
function serve(string $appDir, string $basePath = '', float $window = 60.0, int $maxApplications = 128): Mode
{
    $handler = new Handler($appDir, $basePath, $window, $maxApplications);

    return AsyncMode::serve($handler->publicPath(), $handler);
}
