#!/bin/bash
# PHP-FPM (behind nginx) against swerve, with and without phasync-ext, on the Laravel skeleton of
# tests/Fixtures/app (tests/create-app.sh), 4 processes each. The application runs as in
# production: APP_ENV=production, APP_DEBUG=false, `artisan optimize`; opcache on everywhere.
#
#   benchmarks/run.sh <laravel-major> > benchmarks/results-<laravel-major>.txt
#
# Needs wrk, and PHP-FPM + nginx in user space (FPM_BENCH, default /home/frode/dev/fpm-bench).
# Only one benchmark runs at a time on the machine: every wrk run takes $FPM_BENCH/bench.lock.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
fpm=${FPM_BENCH:-/home/frode/dev/fpm-bench}
ext=${PHASYNC_EXT:-/home/frode/dev/phasync-ext/modules/phasync.so}
port=${PORT:-18710}
"$here/tests/create-app.sh" "$1" > /dev/null 2>&1
app=$(mktemp -d /tmp/swerve-laravel-bench.XXXXXX)
trap 'kill $(jobs -p) 2>/dev/null || true; rm -rf "$app"' EXIT
cp -a "$here/tests/Fixtures/app/." "$app"
cd "$app"
sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/; s/^LOG_LEVEL=.*/LOG_LEVEL=error/' .env
php artisan optimize > /dev/null

echo "# $(date -u +%F) $(lscpu | sed -n 's/^Model name: *//p'), $(nproc) CPUs"
echo "# $(php -r 'echo "PHP ", PHP_VERSION;'), Laravel $(php artisan --version | grep -o '[0-9.]*$'), $(php -d extension="$ext" vendor/bin/swerve --version | tr '\n' ' ')"
echo "# wrk -t4 -c64 -d10s after a 3 s warm-up; 4 PHP-FPM children or 4 swerve workers"

wait_for() { until curl -s -o /dev/null "http://127.0.0.1:$port/api/json"; do sleep 0.2; done; }
run() { # name
    # A session to come back to: its cookies, for the counter
    cookie=$(curl -s -c - -o /dev/null "http://127.0.0.1:$port/counter" | awk '$6 ~ /session|XSRF/ {printf "%s=%s; ", $6, $7}')
    for path in /api/json / /counter; do
        header=(); [ "$path" = /counter ] && header=(-H "Cookie: $cookie")
        flock "$fpm/bench.lock" wrk -t4 -c64 -d3s "${header[@]}" "http://127.0.0.1:$port$path" > /dev/null
        echo "## $1 $path"
        flock "$fpm/bench.lock" wrk -t4 -c64 -d10s "${header[@]}" "http://127.0.0.1:$port$path"
    done
}

"$fpm/serve.sh" "$app/public" "$port" 4 & pid=$!
wait_for; run php-fpm; kill $pid; wait $pid 2>/dev/null || true

for php in "php -d opcache.enable_cli=1" "php -d opcache.enable_cli=1 -d extension=$ext"; do
    $php vendor/bin/swerve --workers=4 --http=127.0.0.1:$port --public=public -q swerve.php & pid=$!
    wait_for; run "swerve$([[ $php == *extension* ]] && echo ' + phasync-ext')"; kill $pid; wait $pid 2>/dev/null || true
done
