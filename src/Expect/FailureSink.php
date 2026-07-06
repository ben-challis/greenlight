<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;

/**
 * Where a failed expectation goes: thrown immediately (the default) or
 * collected for aggregation by Expect::softly().
 *
 * @internal
 */
interface FailureSink
{
    public function report(FailureDetail $detail): void;
}
