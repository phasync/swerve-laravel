<?php

namespace Swerve\Laravel;

/**
 * The context-local state ({@see \phasync::$contextState}) of one pooled application, bound to the
 * context of each request the application serves.
 *
 * @internal
 */
final class AppState
{
    /** @var array<string, mixed> */
    public array $state;

    /** How many slots of {@see \phasync::$contextStateDefaults} this state has had a chance to take */
    private int $seen;

    public function __construct()
    {
        $this->state = \phasync::$contextStateDefaults;
        $this->seen  = \count($this->state);
    }

    /** Take the slots of classes declared since this state was last synced; the ones it has keep their values. */
    public function sync(): void
    {
        if (\count(\phasync::$contextStateDefaults) !== $this->seen) {
            $this->state += \phasync::$contextStateDefaults;
            $this->seen   = \count(\phasync::$contextStateDefaults);
        }
    }

    /** Make this the state of the running coroutine's context. */
    public function adopt(): void
    {
        $this->sync();
        \phasync::adoptContextState($this->state);
    }
}
