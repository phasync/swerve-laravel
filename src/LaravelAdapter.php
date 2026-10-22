<?php

namespace Swerve\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Swerve\Adapter;
use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\Handlers;
use Swerve\Mode;

/**
 * Runs this package's handlers: a {@see Handler} (a pool of applications, safe for overlapping
 * requests) in any mode, and a bare Laravel application or its `Illuminate\Contracts\Http\Kernel`
 * the application booted itself in `PersistentMode` only. One application serves one request at
 * a time, and `AsyncMode` overlaps requests on the application's handler, so a bare application
 * or kernel there is refused at startup, with {@see serve()} (pooled applications) as the way to
 * run a Laravel application in `AsyncMode`.
 *
 * Registered once, from this package's Composer autoload "files" entry
 * (see integrate-with-swerve.php), before any `swerve.php` runs.
 */
final class LaravelAdapter implements Adapter
{
    private static bool $registered = false;

    /** @var array<int, SingleKernelHandler> one per bare application or kernel, by its object id */
    private array $single = [];

    /** Register this adapter once, even if this runs more than once (see integrate-with-swerve.php). */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        Handlers::register(new self());
    }

    public function canHandle(object $handler, Mode $mode): bool
    {
        if ($handler instanceof Handler) {
            return true;
        }
        if (!$handler instanceof Application && !$handler instanceof HttpKernel) {
            return false;
        }
        if ($mode instanceof AsyncMode) {
            throw new \LogicException('A bare Laravel application or HTTP kernel keeps request state in process-wide pointers and serves one request at a time: use PersistentMode, or Swerve\\Laravel\\serve(), which pools applications, for AsyncMode');
        }

        return true;
    }

    public function handle(ClientRequest $request, object $handler): void
    {
        if ($handler instanceof Handler) {
            $handler->handle($request);

            return;
        }
        /* @var Application|HttpKernel $handler */
        ($this->single[\spl_object_id($handler)] ??= new SingleKernelHandler($handler))->handle($request);
    }
}
