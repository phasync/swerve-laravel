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
# Our own packages are the development versions: phasync/swerve and phasync/phasync at dev-main
composer config minimum-stability dev
composer config prefer-stable true
# A copy, not a symlink: the checkout contains this application, and Pest scans tests/
composer config repositories.swerve-laravel '{"type": "path", "url": "../../..", "options": {"symlink": false}}'
# SWERVE_PATH, PHASYNC_PATH: working trees to symlink instead of the dev-main on Packagist
if [ -n "${SWERVE_PATH:-}" ]; then
    composer config repositories.swerve "{\"type\": \"path\", \"url\": \"$SWERVE_PATH\", \"options\": {\"symlink\": true, \"versions\": {\"phasync/swerve\": \"dev-main\"}}}"
fi
if [ -n "${PHASYNC_PATH:-}" ]; then
    composer config repositories.phasync "{\"type\": \"path\", \"url\": \"$PHASYNC_PATH\", \"options\": {\"symlink\": true, \"versions\": {\"phasync/phasync\": \"dev-main\"}}}"
fi
rm -rf vendor/phasync/swerve-laravel # composer won't update a copy that lies inside its source
# swerve's own requirement is a release of phasync: dev-main, aliased, satisfies it
composer require --no-interaction --no-progress --with-all-dependencies 'phasync/phasync:dev-main as 2.0.0' 'phasync/swerve-laravel:*@dev'

# SQLite in WAL mode with a busy timeout: with the defaults, concurrent session writes time out
sed -i "s/'busy_timeout' => null/'busy_timeout' => 5000/; s/'journal_mode' => null/'journal_mode' => 'wal'/; s/'synchronous' => null/'synchronous' => 'normal'/" config/database.php

cp ../routes/swerve-tests.php routes/
grep -q swerve-tests.php routes/web.php || echo "require __DIR__.'/swerve-tests.php';" >> routes/web.php

# An application subclass, which bootstrap/app.php returns when APP_CLASS is set; and a model that
# registers a listener and a global scope in boot(), with an observer registered by a provider
cp -R ../src/. app/
cp ../migrations/* database/migrations/
grep -q APP_CLASS bootstrap/app.php || sed -i 's/^return Application::configure(/return (getenv("APP_CLASS") ?: Application::class)::configure(/' bootstrap/app.php
grep -q GadgetServiceProvider bootstrap/providers.php || sed -i 's/AppServiceProvider::class,/&\n    App\\Providers\\GadgetServiceProvider::class,/' bootstrap/providers.php
php artisan migrate --force --no-interaction > /dev/null
# The only file an application adds to run on swerve
printf "<?php\n\nrequire __DIR__.'/vendor/autoload.php';\n\nreturn new Swerve\\\\Laravel\\\\Handler(__DIR__, (string) getenv('APP_BASE_PATH'), (float) (getenv('APP_IDLE_SECONDS') ?: 60));\n" > swerve.php
