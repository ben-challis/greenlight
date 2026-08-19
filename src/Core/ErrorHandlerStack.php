<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Removes a selected PHP error handler and reinstalls handlers that were above it.
 *
 * @internal
 */
final class ErrorHandlerStack
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function remove(callable $handler): void
    {
        $laterHandlers = [];

        while (($active = self::active()) !== null) {
            \restore_error_handler();

            if ($active === $handler) {
                self::restore($laterHandlers);

                return;
            }

            $laterHandlers[] = $active;
        }

        self::restore($laterHandlers);
    }

    private static function active(): ?callable
    {
        $probe = static fn(): bool => true;
        $active = \set_error_handler($probe);
        \restore_error_handler();

        return $active;
    }

    /** @param list<callable> $handlers */
    private static function restore(array $handlers): void
    {
        foreach (\array_reverse($handlers) as $handler) {
            \set_error_handler($handler);
        }
    }
}
