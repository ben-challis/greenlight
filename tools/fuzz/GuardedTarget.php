<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

/** @internal */
final class GuardedTarget
{
    private function __construct() {}

    /**
     * Converts a warning from target code into a fuzzing crash.
     *
     * @param \Closure(string): void $target
     *
     * @return \Closure(string): void
     */
    public static function wrap(\Closure $target): \Closure
    {
        return static function (string $input) use ($target): void {
            \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                if ((\error_reporting() & $severity) === 0) {
                    return false;
                }

                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            try {
                $target($input);
            } finally {
                \restore_error_handler();
            }
        };
    }
}
