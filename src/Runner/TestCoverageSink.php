<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageMap;

/**
 * Receives one completed logical test's line coverage.
 *
 * @internal
 */
interface TestCoverageSink
{
    public function record(TestId $id, CoverageMap $coverage): void;
}
