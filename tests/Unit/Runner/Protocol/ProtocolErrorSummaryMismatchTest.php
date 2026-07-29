<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\ProtocolError;

final class ProtocolErrorSummaryMismatchTest
{
    #[Test]
    public function summaryMismatchDistinguishesReportedAndObservedTotals(): void
    {
        $error = ProtocolError::summaryMismatch(
            'worker-7',
            '{"passed":1,"failed":0}',
            '{"passed":2,"failed":0}',
        );

        Expect::that($error->getMessage())
            ->because(
                'a summary mismatch MUST identify which totals the worker reported '
                . 'and which totals the event stream observed',
            )
            ->toBe(
                'Worker "worker-7" reported a summary of {"passed":2,"failed":0}, '
                . 'but its event stream totals {"passed":1,"failed":0}. '
                . 'This difference indicates an internal accounting error. Greenlight stopped the run.',
            );
    }
}
