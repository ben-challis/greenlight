<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\CoverageMap;

/**
 * Evaluates total coverage against the configured CI limits.
 *
 * @internal
 */
final class CoverageGate
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @return list<non-empty-string> */
    public static function failures(CoverageConfiguration $configuration, CoverageMap $coverage): array
    {
        $failures = [];
        $percentage = \round($coverage->totalPercentage(), 2, \RoundingMode::HalfAwayFromZero);

        if ($configuration->minimumPercentage !== null && $percentage < $configuration->minimumPercentage) {
            $failures[] = \sprintf(
                'Coverage gate failed: %.2f%% is less than the minimum %.2f%%.',
                $percentage,
                $configuration->minimumPercentage,
            );
        }

        $uncovered = $coverage->uncoveredLineTotal();

        if ($configuration->maximumUncoveredLines !== null && $uncovered > $configuration->maximumUncoveredLines) {
            $failures[] = \sprintf(
                'Coverage gate failed: %d uncovered %s %s the maximum %d.',
                $uncovered,
                $uncovered === 1 ? 'line' : 'lines',
                $uncovered === 1 ? 'exceeds' : 'exceed',
                $configuration->maximumUncoveredLines,
            );
        }

        return $failures;
    }
}
