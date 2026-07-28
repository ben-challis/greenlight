<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Removes one owned error handler and preserves handlers that were installed later.
 *
 * @internal
 */
final class ErrorHandlerStack
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function remove(\Closure $ownedHandler): void
    {
        /** @var list<callable|null> $laterHandlers */
        $laterHandlers = [];

        while (true) {
            $activeHandler = self::activeHandler();

            if ($activeHandler === $ownedHandler) {
                \restore_error_handler();

                break;
            }

            $laterHandlers[] = $activeHandler;
            \restore_error_handler();
        }

        foreach (\array_reverse($laterHandlers) as $laterHandler) {
            \set_error_handler($laterHandler);
        }
    }

    private static function activeHandler(): ?callable
    {
        $activeHandler = \set_error_handler(null);
        \restore_error_handler();

        return $activeHandler;
    }
}
