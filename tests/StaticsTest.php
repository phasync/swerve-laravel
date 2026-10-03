<?php

/*
 * Requests overlap in a worker, so every static property of Laravel and of the classes it runs on is
 * shared by requests that run in different applications. tests/statics/allowlist.php says what each
 * one means for that, and this test keeps the list complete: a static property that is not in it (a new
 * Laravel release added one) must be classified, and a row for a property that no longer exists must go.
 * It needs no server: one PHP process loads the classes of the fixture application and reflects over them.
 */

/**
 * The static properties of the fixture application's classes, reflected once per run.
 *
 * @return array{version: string, found: array<string, string>, failed: list<string>}
 */
function statics_of_the_application(): array
{
    static $statics;
    $statics ??= \json_decode((string) \shell_exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(__DIR__ . '/statics/enumerate.php') . ' ' . \escapeshellarg(APP)), true, flags: \JSON_THROW_ON_ERROR);

    return $statics;
}

beforeEach(function () {
    // The list was made from Laravel 13; the statics of the other releases are classified as they are tested
    $version = statics_of_the_application()['version'];
    \str_starts_with($version, '13.') || $this->markTestSkipped("the classification is of Laravel 13, this is $version");
});

test('every static property is classified, and every classified Laravel property exists', function () {
    ['found' => $found, 'failed' => $failed] = statics_of_the_application();
    $classified                              = require __DIR__ . '/statics/allowlist.php';
    expect(\array_keys(\array_diff_key($found, $classified)))->toBe([], 'static properties missing from tests/statics/allowlist.php: classify them');
    // Other packages may be a release other than the one the list was made from, so only Laravel's own rows must exist
    $stale = \array_filter(\array_keys(\array_diff_key($classified, $found)), static fn (string $key) => \str_starts_with($key, 'Illuminate\\') && !\in_array(\explode('::$', $key)[0], $failed, true));
    expect(\array_values($stale))->toBe([], 'rows of tests/statics/allowlist.php for properties that no longer exist');
});

test('every row is well formed, a property that became public is classified again, and what is not handled says why', function () {
    ['found' => $found] = statics_of_the_application();
    foreach (require __DIR__ . '/statics/allowlist.php' as $key => [$category, $visibility, $access, $adapter, $note]) {
        expect($category)->toBeIn(['POINTER', 'CALLBACK-LIST', 'DATA', 'CONFIG-SET-ONCE-AT-BOOT', 'HARMLESS-CACHE', 'CONSTANT', 'NOT-IN-HTTP-PATH', 'UNHANDLED'], $key);
        expect($visibility)->toBeIn(['public', 'protected', 'private'], $key);
        expect($access)->toBeIn(['a', 'b', 'c', 'bc'], $key);
        expect($adapter)->toBeIn(['handled', 'partial', 'none', 'planned', 'n/a'], $key);
        expect($note)->not->toBe('', $key);
        if (isset($found[$key])) {
            expect($found[$key])->toBe($visibility, $key);
        }
        if ('UNHANDLED' === $category) {
            expect($adapter)->toBeIn(['none', 'partial', 'planned'], $key);
        }
    }
});
