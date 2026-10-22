<?php

/*
 * LaravelAdapter's contract: the pooled Handler runs in Persistent and Async mode; a bare
 * Laravel application, or its Illuminate\Contracts\Http\Kernel, that the application booted
 * itself runs in Persistent mode only (refused in Async, with the reason); and the adapter
 * registers itself once through Composer's autoload "files", even if that runs more than once.
 *
 * Each test writes its own swerve.php into a fresh directory and starts swerve there (through
 * the fixture app's installed vendor/bin/swerve, so the application's dependencies, including
 * the autoloader, are already there).
 */

/** A temporary directory with $swervePhp as its swerve.php. */
function modes_dir(string $swervePhp): string
{
    $dir = \tempnam(\sys_get_temp_dir(), 'swerve-laravel-modes-');
    \unlink($dir);
    \mkdir($dir);
    \file_put_contents("$dir/swerve.php", "<?php\n$swervePhp");

    return $dir;
}

/** Starts swerve in $dir through the fixture app's own vendor/bin/swerve. */
function modes_start(string $dir, int $workers = 1): array
{
    [$from, $to] = \array_map('intval', \explode('-', \getenv('SWERVE_TEST_PORTS') ?: '18700-18749'));
    for ($port = $from; $port <= $to; ++$port) {
        if ($socket = @\stream_socket_server("tcp://127.0.0.1:$port")) {
            \fclose($socket);
            break;
        }
    }
    $addr = "127.0.0.1:$port";
    $log  = \tempnam(\sys_get_temp_dir(), 'swerve-laravel-modes-log');
    $bin  = \realpath(APP . '/vendor/bin/swerve');
    $php  = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd  = 'exec ' . \PHP_BINARY . " -d opcache.enable_cli=1 $php " . \escapeshellarg($bin) . " --workers=$workers --grace=2 --http=$addr --log=" . \escapeshellarg($log);
    $proc = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $dir, \getenv());

    return [$proc, $addr, $log];
}

/** Whether swerve answered at $addr before $deadline. */
function modes_ready(string $addr, float $deadline): bool
{
    $curl = \curl_init("http://$addr/json");
    \curl_setopt_array($curl, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 1]);
    while (false === \curl_exec($curl)) {
        if (\microtime(true) > $deadline) {
            return false;
        }
        \usleep(50_000);
    }

    return true;
}

$app = APP;
$src = \realpath(__DIR__ . '/../src/integrate-with-swerve.php');

it('runs the pooled Handler in PersistentMode', function () use ($app) {
    $dir = modes_dir(<<<PHP
        require '$app/vendor/autoload.php';
        return Swerve\PersistentMode::serve(null, new Swerve\Laravel\Handler('$app'));
        PHP);
    [$proc, $addr, $log] = modes_start($dir);
    try {
        expect(modes_ready($addr, \microtime(true) + 10))->toBeTrue(@\file_get_contents($log));
        expect((new Browser($addr))->json('/json'))->toMatchArray(['hello' => 'world']);
    } finally {
        app_stop($proc);
    }
});

it('runs a bare application the application booted itself in PersistentMode, one request at a time', function () use ($app) {
    $dir = modes_dir(<<<PHP
        require '$app/vendor/autoload.php';
        \$application = require '$app/bootstrap/app.php';
        return Swerve\PersistentMode::serve(null, \$application);
        PHP);
    [$proc, $addr, $log] = modes_start($dir);
    try {
        expect(modes_ready($addr, \microtime(true) + 10))->toBeTrue(@\file_get_contents($log));
        $b = new Browser($addr);
        expect($b->json('/json'))->toMatchArray(['hello' => 'world'])
            ->and($b->json('/json'))->toMatchArray(['hello' => 'world']);
    } finally {
        app_stop($proc);
    }
});

it('runs a bare HTTP kernel the application booted itself in PersistentMode', function () use ($app) {
    $dir = modes_dir(<<<PHP
        require '$app/vendor/autoload.php';
        \$application = require '$app/bootstrap/app.php';
        \$kernel = \$application->make(Illuminate\Contracts\Http\Kernel::class);
        return Swerve\PersistentMode::serve(null, \$kernel);
        PHP);
    [$proc, $addr, $log] = modes_start($dir);
    try {
        expect(modes_ready($addr, \microtime(true) + 10))->toBeTrue(@\file_get_contents($log));
        expect((new Browser($addr))->json('/json'))->toMatchArray(['hello' => 'world']);
    } finally {
        app_stop($proc);
    }
});

it('refuses a bare application in AsyncMode, with the reason', function () use ($app) {
    $dir = modes_dir(<<<PHP
        require '$app/vendor/autoload.php';
        \$application = require '$app/bootstrap/app.php';
        return Swerve\AsyncMode::serve(null, \$application);
        PHP);
    [$proc, $addr, $log] = modes_start($dir);
    $ready               = modes_ready($addr, \microtime(true) + 5);
    app_stop($proc);

    expect($ready)->toBeFalse()
        ->and(\file_get_contents($log))->toContain('A bare Laravel application')
        ->and(\file_get_contents($log))->toContain('PersistentMode');
});

it('registers LaravelAdapter once through the autoload "files" entry, even required twice', function () use ($app, $src) {
    $dir = modes_dir(<<<PHP
        require '$app/vendor/autoload.php';
        require '$src';
        require '$src';
        return Swerve\AsyncMode::serve(null, new Swerve\Laravel\Handler('$app'));
        PHP);
    [$proc, $addr, $log] = modes_start($dir);
    try {
        // Several adapters claiming the same handler would fail startup (Swerve\Handlers::resolve()):
        // requiring the registration file twice must not register the adapter twice
        expect(modes_ready($addr, \microtime(true) + 10))->toBeTrue(@\file_get_contents($log));
        expect((new Browser($addr))->json('/json'))->toMatchArray(['hello' => 'world']);
    } finally {
        app_stop($proc);
    }
});
