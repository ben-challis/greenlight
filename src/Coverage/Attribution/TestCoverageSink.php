<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Attribution;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Test\TestId;

/** Receives one complete coverage observation for one test. */
/** @internal */
interface TestCoverageSink
{
    public function record(TestId $id, CoverageMap $coverage): void;
}
