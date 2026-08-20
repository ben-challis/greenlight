<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\DecimalInteger;
use Greenlight\Core\ErrorTrap;

/**
 * Uses LINES, then tput, then 24.
 *
 * @internal
 */
final class TerminalRowsResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function resolve(): int
    {
        $lines = self::positiveRows(\getenv('LINES'));

        if ($lines !== null) {
            return $lines;
        }

        $probed = \function_exists('exec')
            ? ErrorTrap::run(static fn() => \exec('tput lines 2>/dev/null'))
            : false;

        return self::positiveRows($probed) ?? 24;
    }

    /**
     * @return positive-int|null
     */
    private static function positiveRows(string|false $raw): ?int
    {
        if (!\is_string($raw)) {
            return null;
        }

        $rows = DecimalInteger::parse($raw);

        return $rows !== null && $rows > 0 ? $rows : null;
    }
}
