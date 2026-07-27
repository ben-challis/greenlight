<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ObservationLog;

final class ObservationLogTest
{
    #[Test]
    public function renderedHistoryIsBoundedWithATruncationMarker(): void
    {
        $log = new ObservationLog(0.0);
        $log->record(0.0, \str_repeat('x', 3_000));

        Expect::that($log->render())
            ->because('rendered observation history stays within its byte bound')
            ->toHaveLength(2_048)
            ->toEndWith('...');
    }

    #[Test]
    public function anIdenticalTailGroupDoesNotRepeatTheFirstObservation(): void
    {
        $log = new ObservationLog(0.0);
        $log->record(0.0, 'first');
        $log->record(0.0, 'second');
        $log->record(0.0, 'first');

        Expect::that($log->render())
            ->because('an identical tail group does not repeat the first observation')
            ->toBe("+0.0ms first\n+0.0ms second");
    }
}
