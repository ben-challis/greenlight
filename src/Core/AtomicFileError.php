<?php

declare(strict_types=1);

namespace Greenlight\Core;

/** Messages include the target path and each warning that PHP supplies. */
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
