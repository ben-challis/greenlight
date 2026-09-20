<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\RunAcceptancePolicy;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\RunPolicy;

/**
 * Applies the configured skipped-test and retried-pass run rules.
 *
 * @internal
 */
final readonly class ConfiguredRunAcceptance implements Prioritized, RunAcceptancePolicy
{
    public function __construct(private RunPolicy $policy) {}

    #[\Override]
    public function priority(): int
    {
        return \PHP_INT_MIN;
    }

    /** @param non-negative-int $retriedPasses */
    #[\Override]
    public function failureMessage(ResultSummary $summary, int $retriedPasses): ?string
    {
        if (!$summary->isSuccessful()) {
            return null;
        }

        $messages = [];

        if ($this->policy->failOnSkipped && $summary->skipped > 0) {
            $messages[] = \sprintf(
                'Greenlight failed because the fail-on-skipped policy found %d skipped %s.',
                $summary->skipped,
                $summary->skipped === 1 ? 'test' : 'tests',
            );
        }

        if ($this->policy->failOnRetriedPass && $retriedPasses > 0) {
            $messages[] = \sprintf(
                'Greenlight failed because the fail-on-retried-pass policy found %d %s that passed after retry.',
                $retriedPasses,
                $retriedPasses === 1 ? 'test' : 'tests',
            );
        }

        return $messages === [] ? null : \implode("\n", $messages);
    }
}
