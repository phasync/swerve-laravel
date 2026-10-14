<?php

namespace Swerve\Laravel;

use Swerve\RequestHandler;

/**
 * The entry point of the `laravel` swerve adapter, which swerve's workers call once each, with the
 * application directory (see the `extra.swerve` section of this package's composer.json): the
 * application's own `bootstrap/app.php`, served by a {@see Handler} with its defaults.
 *
 * A swerve.php that returns `new Swerve\RequestHandler((new Handler(__DIR__, ...))->handle(...))`,
 * run with `--adapter=swerve`, gives the handler other settings.
 *
 * @param string $appDir the application directory: where swerve was started
 */
function entry(string $appDir): RequestHandler
{
    return new RequestHandler((new Handler($appDir))->handle(...));
}
