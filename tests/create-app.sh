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
composer config minimum-stability alpha
composer config prefer-stable true
# A copy, not a symlink: the checkout contains this application, and Pest scans tests/
composer config repositories.swerve-laravel '{"type": "path", "url": "../../..", "options": {"symlink": false}}'
# SWERVE_PATH: a swerve checkout to use instead of the release on Packagist, $SWERVE_VERSION its version
if [ -n "${SWERVE_PATH:-}" ]; then
    composer config repositories.swerve "{\"type\": \"path\", \"url\": \"$SWERVE_PATH\", \"options\": {\"symlink\": false, \"versions\": {\"phasync/swerve\": \"$SWERVE_VERSION\"}}}"
fi
rm -rf vendor/phasync/swerve-laravel # composer won't update a copy that lies inside its source
composer require --no-interaction --no-progress --with-all-dependencies 'phasync/swerve-laravel:*@dev'

# SQLite in WAL mode with a busy timeout: with the defaults, concurrent session writes time out
sed -i "s/'busy_timeout' => null/'busy_timeout' => 5000/; s/'journal_mode' => null/'journal_mode' => 'wal'/; s/'synchronous' => null/'synchronous' => 'normal'/" config/database.php

cp ../routes/swerve-tests.php routes/
grep -q swerve-tests.php routes/web.php || echo "require __DIR__.'/swerve-tests.php';" >> routes/web.php
# The only file an application adds to run on swerve
printf "<?php\n\nrequire __DIR__.'/vendor/autoload.php';\n\nreturn new Swerve\\\\Laravel\\\\Handler(__DIR__, (string) getenv('APP_BASE_PATH'));\n" > swerve.php
