<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\SourceLocation;

/**
 * Finds the user-facing call site of a failed expectation: the innermost
 * backtrace frame whose file lies outside this component's source directory.
 *
 * @internal
 */
final class CallSite
{
    private const int MAX_FRAMES = 40;

    private function __construct() {}

    public static function capture(): ?SourceLocation
    {
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES) as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (!\is_string($file) || $file === '' || !\is_int($line) || $line < 1) {
                continue;
            }

            if (\str_starts_with($file, __DIR__ . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            return new SourceLocation($file, $line);
        }

        return null;
    }
}
