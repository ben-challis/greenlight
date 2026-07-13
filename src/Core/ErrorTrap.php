<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Runs one native operation with engine diagnostics routed away from any
 * host error handler.
 *
 * run() installs an error handler that records the last message and marks
 * it handled, invokes the operation, and restores the previous handler on
 * the way out. Failure still signals through the operation's return value;
 * the recorded message exists to enrich whatever error the caller raises.
 *
 * This replaces the @ operator: @ still exposes the diagnostic to any
 * installed error handler that ignores error_reporting(), and it discards
 * the message this helper keeps.
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
     * @param string|null $warning the last engine message raised during the
     *   operation, null when none was raised
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
