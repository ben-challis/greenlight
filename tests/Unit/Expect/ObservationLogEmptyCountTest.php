<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ObservationLog;

use function Greenlight\expect;

final class ObservationLogEmptyCountTest
{
    #[Test]
    public function aFreshLogReportsOneObservation(): void
    {
        $log = new ObservationLog(0.0);

        expect($log->count())
            ->because('an observation log MUST report at least one observation')
            ->toBe(1);
    }
}
