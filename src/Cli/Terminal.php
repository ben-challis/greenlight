<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\ErrorTrap;

/**
 * Determines whether a stream is attached to an interactive terminal. The probe
 * does not send engine warnings to host error handlers.
 *
 * @internal
 */
final class Terminal
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param resource $stream
     */
    public static function isTty($stream): bool
    {
        return ErrorTrap::run(static fn(): bool => \stream_isatty($stream));
    }
}
