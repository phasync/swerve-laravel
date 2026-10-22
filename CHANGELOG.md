# Changelog

## Unreleased

Ported to swerve 0.2's mode and adapter contract.

- `swerve.php` now returns a mode: `Swerve\Laravel\serve($appDir)` returns
  `Swerve\AsyncMode::serve($app->publicPath(), new Handler($appDir))`, the pooled applications this
  package always ran. `PersistentMode::serve($publicRoot, $handler)` may instead be given a bare
  application (or its `Illuminate\Contracts\Http\Kernel`) the application booted and runs itself,
  one request at a time, with no pool; `AsyncMode` refuses one, with the reason.
- `Swerve\Laravel\LaravelAdapter` replaces `entry()`: registered once, from this package's
  Composer autoload `files`, through swerve's `Swerve\Handlers::register()`.
- Removed: `entry()`, the `extra.swerve` section of composer.json, and `--public` (the mode's
  public root covers it; `serve()` passes the application's own public path).

## 0.1.1 (2026-10-19)

Documentation: clarify application-level state isolation and the Scope-based fixes.

## 0.1.0 (2026-10-19)

The first release.
