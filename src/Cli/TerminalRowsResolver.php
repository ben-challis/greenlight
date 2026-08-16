<?php

declare(strict_types=1);

namespace Greenlight\Cli;

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
        $linesEnv = \getenv('LINES');
        $lines = $linesEnv === false || $linesEnv === '' ? 0 : (int) $linesEnv;

        if ($lines > 0) {
            return $lines;
        }

        $probed = (int) ErrorTrap::run(static fn(): string|false => \exec('tput lines 2>/dev/null'));

        return $probed > 0 ? $probed : 24;
    }
}
