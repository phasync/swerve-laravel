<?php

namespace Swerve\Laravel;

use Psr\Http\Message\StreamInterface;

/**
 * The body of a streamed response, as swerve reads it: each read waits for the Laravel callback
 * to echo more. Only swerve holds it; when swerve drops it (the client left), the Pipe learns
 * that the writer's bytes go nowhere.
 *
 * @internal
 */
final class StreamedBody implements StreamInterface
{
    private int $offset = 0;

    public function __construct(private readonly Pipe $pipe)
    {
    }

    public function __destruct()
    {
        $this->pipe->gone = true;
        \phasync::raiseFlag($this->pipe);
    }

    public function read(int $length): string
    {
        $pipe = $this->pipe;
        while ('' === $pipe->buffer && !$pipe->ended && !$pipe->failed) {
            \phasync::awaitFlag($pipe);
        }
        if ('' === $pipe->buffer && $pipe->failed) {
            throw new \RuntimeException('The streamed response failed; see the application log');
        }
        $bytes        = \substr($pipe->buffer, 0, $length);
        $pipe->buffer = \substr($pipe->buffer, \strlen($bytes));
        $this->offset += \strlen($bytes);
        \phasync::raiseFlag($pipe);

        return $bytes;
    }

    public function eof(): bool
    {
        return '' === $this->pipe->buffer && $this->pipe->ended;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->pipe->gone = true;
        \phasync::raiseFlag($this->pipe);
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('A streamed response is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('A streamed response is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('A streamed response is written by its callback');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }
}
