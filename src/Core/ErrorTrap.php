<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Runs one native operation. It does not send engine diagnostics to a host error handler.
 *
 * run() installs an error handler that records the last message and handles
 * it. It then invokes the operation and restores the previous handler.
 * The return value of the operation still identifies a failure. The recorded
 * message gives more information for an error that the caller raises.
 *
 * This operation replaces the @ operator. That operator sends the diagnostic
 * to an installed error handler that ignores error_reporting(). It also
 * discards the message that this helper keeps.
 *
 * @internal
 */
final class ErrorTrap
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     * @param string|null $warning The last engine message from the operation,
     *   or null if there was no message
     *
     * @param-out string|null $warning
     *
     * @return T
     */
    public static function run(\Closure $operation, ?string &$warning = null): mixed
    {
        $warning = null;
        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return $operation();
        } finally {
            \restore_error_handler();
        }
    }
}
