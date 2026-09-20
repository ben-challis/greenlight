<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Result\ResultSummary;

/**
 * Evaluates an otherwise successful run without changing test outcomes.
 *
 * Greenlight calls each policy one time after reporters finish. It runs lower
 * priorities first and uses registration order for equal priorities. Return
 * null to accept the run. Return a non-empty failure message to reject it.
 * Greenlight runs all policies and reports all rejection messages.
 */
interface RunAcceptancePolicy extends Plugin
{
    /** @param non-negative-int $retriedPasses */
    public function failureMessage(ResultSummary $summary, int $retriedPasses): ?string;
}
