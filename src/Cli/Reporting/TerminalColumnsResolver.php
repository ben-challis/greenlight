<?php

declare(strict_types=1);

namespace Greenlight\Cli\Reporting;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * Uses COLUMNS, then tput, then 80.
 *
 * @internal
 */
final class TerminalColumnsResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @return positive-int */
    public static function resolve(): int
    {
        $columns = self::positiveColumns(\getenv('COLUMNS'));

        if ($columns !== null) {
            return $columns;
        }

        $probed = \function_exists('exec')
            ? ErrorTrap::run(static fn() => \exec('tput cols 2>/dev/null'))
            : false;

        return self::positiveColumns($probed) ?? 80;
    }

    /**
     * @return positive-int|null
     */
    private static function positiveColumns(string|false $raw): ?int
    {
        if (!\is_string($raw)) {
            return null;
        }

        $columns = \filter_var(\trim($raw), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return \is_int($columns) && $columns > 0 ? $columns : null;
    }
}
