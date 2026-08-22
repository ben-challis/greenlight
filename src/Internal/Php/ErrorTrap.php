<?php

declare(strict_types=1);

namespace Greenlight\Internal\Php;

/**
 * Runs one native operation. It does not send engine diagnostics to a host error handler.
 *
 * run() installs an error handler that records the last message and suppresses
 * each diagnostic. It then invokes the operation and restores the previous
 * handler.
 * The return value of the operation still identifies a failure. The recorded
 * message gives more information for an error that the caller raises.
 * If the operation throws, an optional wrap callback can replace its throwable.
 * run() restores the previous handler before it invokes this callback.
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
     * @param (\Closure(\Throwable): \Throwable)|null $wrap Creates a replacement
     *   for a throwable from the operation
     *
     * @param-out string|null $warning
     *
     * @return T
     */
    public static function run(
        \Closure $operation,
        ?string &$warning = null,
        ?\Closure $wrap = null,
    ): mixed {
        $warning = null;
        $handler = static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        };

        \set_error_handler($handler);

        try {
            return $operation();
        } catch (\Throwable $failure) {
            if (!$wrap instanceof \Closure) {
                throw $failure;
            }
        } finally {
            ErrorHandlerStack::remove($handler);
        }

        throw $wrap($failure);
    }
}
