<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;

/**
 * Merges per-test observations into aggregate coverage and an optional spool.
 *
 * @internal
 */
final class CollectingTestCoverageSink implements TestCoverageSink
{
    private CoverageMap $coverage;

    public function __construct(private readonly ?TestCoverageStore $store = null)
    {
        $this->coverage = CoverageMap::empty();
    }

    /** @throws CoverageError */
    #[\Override]
    public function record(TestId $id, CoverageMap $coverage): void
    {
        $this->coverage = $this->coverage->merge($coverage);
        $this->store?->record($id, $coverage);
    }

    public function coverage(): CoverageMap
    {
        return $this->coverage;
    }
}
