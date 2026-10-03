<?php

/*
 * Prints as JSON every static property of the runtime classes of the Laravel application in $argv[1]:
 * {"version": Laravel's, "found": {"Class::$property": visibility}, "failed": [classes that could not be loaded]}.
 * Run by tests/StaticsTest.php in a process of its own, so that the classes it loads are the application's.
 */

$app = $argv[1];
require "$app/vendor/autoload.php";

$roots = [
    'laravel/framework/src', 'laravel/octane/src', 'laravel/serializable-closure/src', 'laravel/prompts/src', 'nesbot/carbon/src',
    'symfony/http-foundation', 'symfony/http-kernel', 'symfony/routing', 'symfony/translation', 'symfony/translation-contracts', 'symfony/mime',
    'symfony/console', 'symfony/error-handler', 'symfony/string', 'symfony/uid', 'symfony/var-dumper', 'symfony/clock', 'symfony/event-dispatcher',
    'monolog/monolog/src', 'ramsey/uuid/src', 'vlucas/phpdotenv/src', 'dragonmantank/cron-expression/src', 'league/commonmark/src', 'league/flysystem/src',
    'league/mime-type-detection/src', 'egulias/email-validator/src', 'brick/math/src', 'psr', 'carbonphp/carbon-doctrine-types/src', 'voku', 'nette', 'tijsverkoyen',
];

$classes = [];
foreach ($roots as $root) {
    if (!\is_dir("$app/vendor/$root")) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$app/vendor/$root", FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ('php' !== $file->getExtension() || \preg_match('#/(tests?|Tests?|stubs|resources|bin)/#', \substr($file->getPathname(), \strlen("$app/vendor/$root")))) {
            continue;
        }
        $source    = \file_get_contents($file->getPathname());
        $namespace = \preg_match('/^namespace\s+([^;{\s]+)/m', $source, $m) ? $m[1] . '\\' : '';
        if (\preg_match_all('/^(?:abstract\s+|final\s+|readonly\s+)*(?:class|enum)\s+(\w+)/m', $source, $declared)) {
            foreach ($declared[1] as $name) {
                $classes[] = $namespace . $name;
            }
        }
    }
}

$found  = [];
$failed = [];
foreach ($classes as $class) {
    try {
        if (!\class_exists($class)) {
            $failed[] = $class;
            continue;
        }
    } catch (Throwable) { // a class of a package that is not installed
        $failed[] = $class;
        continue;
    }
    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
        if ($property->getDeclaringClass()->getName() === $class) {
            $found["$class::\$" . $property->getName()] = $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private');
        }
    }
}
\ksort($found);
echo \json_encode(['version' => Illuminate\Foundation\Application::VERSION, 'found' => $found, 'failed' => $failed]);
