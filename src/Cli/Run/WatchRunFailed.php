<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

/**
 * A fresh watch-run process could not start or complete its event stream.
 *
 * @internal
 */
final class WatchRunFailed extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function unavailable(): self
    {
        return new self('Watch mode requires a CLI executable and PHP process functions.');
    }

    public static function operation(string $message, ?\Throwable $cause = null): self
    {
        return new self('Watch run failed: ' . $message, previous: $cause);
    }
}
