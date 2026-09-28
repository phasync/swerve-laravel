<?php

namespace Swerve\Laravel;

/**
 * The bytes of a streamed response between the Laravel callback producing them and swerve
 * reading them through a StreamedBody.
 *
 * @internal
 */
final class Pipe
{
    /** Bytes the writer may be ahead of the reader before it waits. */
    private const LIMIT = 65536;

    public string $buffer = '';
    public bool $ended    = false;
    /** The producer failed: the reader throws instead of ending, so the client sees the cut. */
    public bool $failed = false;
    /** The reader is gone: the client left, and swerve dropped the response. */
    public bool $gone = false;

    /** Add bytes, waiting while too many are unread. False when nobody will read them. */
    public function write(string $bytes): bool
    {
        if ($this->gone) {
            return false;
        }
        $this->buffer .= $bytes;
        \phasync::raiseFlag($this);
        while (\strlen($this->buffer) > self::LIMIT && !$this->gone) {
            \phasync::awaitFlag($this);
        }

        return !$this->gone;
    }

    public function end(): void
    {
        $this->ended = true;
        \phasync::raiseFlag($this);
    }
}
