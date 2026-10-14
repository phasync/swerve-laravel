<?php

namespace Swerve\Laravel;

/**
 * The bytes a streamed response's callback echoed, between the output buffer that collects them
 * and the coroutine that writes them to the client; its flag is raised on every change.
 *
 * @internal
 */
final class Pipe
{
    public string $buffer = '';
    /** The callback returned or threw: nothing more comes. */
    public bool $ended = false;
    /** The client left: what the callback echoes goes nowhere. */
    public bool $gone = false;
}
