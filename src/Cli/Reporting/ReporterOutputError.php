<?php

declare(strict_types=1);

namespace Greenlight\Cli\Reporting;

/**
 * Greenlight could not prepare a selected reporter output file.
 *
 * @internal
 */
final class ReporterOutputError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function directoryCreationFailed(string $path, ?string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(
            'Greenlight could not create reporter output directory "%s"%s.',
            $path,
            $reason === null ? '' : ': ' . $reason,
        ), $previous);
    }

    public static function fileOpenFailed(string $path, ?string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(
            'Greenlight could not open reporter output file "%s"%s.',
            $path,
            $reason === null ? '' : ': ' . $reason,
        ), $previous);
    }
}
