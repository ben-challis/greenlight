<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Raised when an atomic file write cannot complete.
 *
 * Always names the target path and, when PHP raised a warning, its message,
 * so a failed write is diagnosable without re-running under a debugger.
 * Callers that treat the write as advisory catch and discard this.
 */
final class AtomicFileError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function cannotNameTemporary(string $path, \Throwable $previous): self
    {
        return new self(\sprintf(
            'Cannot generate a temporary name for "%s": %s',
            $path,
            $previous->getMessage(),
        ), $previous);
    }

    public static function cannotWriteTemporary(string $temporary, ?string $reason): self
    {
        return new self(\sprintf(
            'Cannot write temporary file "%s"%s.',
            $temporary,
            $reason === null ? '' : ': ' . $reason,
        ));
    }

    public static function cannotRename(string $temporary, string $path, ?string $reason): self
    {
        return new self(\sprintf(
            'Cannot rename "%s" to "%s"%s.',
            $temporary,
            $path,
            $reason === null ? '' : ': ' . $reason,
        ));
    }
}
