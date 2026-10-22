#!/bin/sh
# Create the Laravel test application in tests/Fixtures/app, for Laravel version $1:
# the framework's own skeleton, with this package installed from the checkout and the test
# routes of tests/Fixtures/routes added. Idempotent.
set -eu
cd "$(dirname "$0")/Fixtures"
if [ ! -f app/artisan ]; then
    # The skeleton's post-create scripts make the key, the SQLite database and its tables
    composer create-project --no-interaction --no-progress --prefer-dist "laravel/laravel:^$1.0" app
fi
cd app
composer config minimum-stability stable
# A copy, not a symlink: the checkout contains this application, and Pest scans tests/
composer config repositories.swerve-laravel '{"type": "path", "url": "../../..", "options": {"symlink": false}}'
if [ -n "${PHASYNC_PATH:-}" ]; then
    composer config repositories.phasync "{\"type\": \"path\", \"url\": \"$PHASYNC_PATH\", \"options\": {\"symlink\": true, \"versions\": {\"phasync/phasync\": \"dev-main\"}}}"
fi
# SWERVE_PATH: a local checkout of swerve, instead of the release on Packagist. swerve 0.2 is
# unreleased (phasync/swerve#modes): its own dependencies phasync/plug, phasync/babel and
# phasync/hooked are unreleased too, resolved from their own checkouts beside it, not from
# swerve's own "repositories" (composer doesn't follow a dependency's path repositories).
# Temporary, like this package's own repositories (see composer.json).
#
# "symlink": false (a copy, respecting .gitignore since these are git working copies): a symlink
# would carry phasync/plug's own e2e/app/vendor/phasync/plug into this fixture, which
# path-repo-symlinks back to phasync/plug itself. Harmless for phasync/plug's own test suite, but
# Pest's dataset scan (Pest\Bootstrappers\BootFiles) walks every ./tests file with no cycle
# detection, and PHPUnit's own file-iterator follows symlinks into an excluded directory too (it
# filters the results, not the walk): either one, reached through this fixture's
# tests/Fixtures/app/vendor, loops forever.
if [ -n "${SWERVE_PATH:-}" ]; then
    composer config repositories.swerve "{\"type\": \"path\", \"url\": \"$SWERVE_PATH\", \"options\": {\"symlink\": false, \"versions\": {\"phasync/swerve\": \"dev-modes\"}}}"
    composer config repositories.phasync-plug '{"type": "path", "url": "/home/frode/dev/phasync-plug", "options": {"symlink": false}}'
    composer config repositories.phasync-babel '{"type": "path", "url": "/home/frode/dev/phasync-babel", "options": {"symlink": false}}'
    composer config repositories.phasync-hooked '{"type": "path", "url": "/home/frode/dev/phasync-hooked", "options": {"symlink": false}}'
    swerve_constraint='phasync/swerve:dev-modes'
    dev_constraints='phasync/plug:@dev phasync/babel:@dev phasync/hooked:@dev'
else
    composer config --unset repositories.swerve
    composer config --unset repositories.phasync-plug
    composer config --unset repositories.phasync-babel
    composer config --unset repositories.phasync-hooked
    swerve_constraint='phasync/swerve:^0.2'
    dev_constraints=''
fi
rm -rf vendor/phasync/swerve-laravel # composer won't update a copy that lies inside its source
composer require --no-interaction --no-progress --with-all-dependencies "phasync/swerve-laravel:*@dev" "$swerve_constraint" $dev_constraints

# SQLite in WAL mode with a busy timeout: with the defaults, concurrent session writes time out
sed -i "s/'busy_timeout' => null/'busy_timeout' => 5000/; s/'journal_mode' => null/'journal_mode' => 'wal'/; s/'synchronous' => null/'synchronous' => 'normal'/" config/database.php

cp ../routes/swerve-tests.php ../routes/stray-output.php ../routes/isolation.php routes/
grep -q swerve-tests.php routes/web.php || echo "require __DIR__.'/swerve-tests.php';" >> routes/web.php
grep -q stray-output.php routes/web.php || echo "require __DIR__.'/stray-output.php';" >> routes/web.php
grep -q isolation.php routes/web.php || echo "require __DIR__.'/isolation.php';" >> routes/web.php

# An application subclass, which bootstrap/app.php returns when APP_CLASS is set; and a model that
# registers a listener and a global scope in boot(), with an observer registered by a provider
cp -R ../src/. app/
cp ../migrations/* database/migrations/
grep -q APP_CLASS bootstrap/app.php || sed -i 's/^return Application::configure(/return (getenv("APP_CLASS") ?: Application::class)::configure(/' bootstrap/app.php
grep -q GadgetServiceProvider bootstrap/providers.php || sed -i 's/AppServiceProvider::class,/&\n    App\\Providers\\GadgetServiceProvider::class,/' bootstrap/providers.php
php artisan migrate --force --no-interaction > /dev/null
# The tests' own settings (APP_BASE_PATH, APP_WINDOW, APP_MAX_APPLICATIONS), read at swerve.php's startup
cp ../swerve.php swerve.php
