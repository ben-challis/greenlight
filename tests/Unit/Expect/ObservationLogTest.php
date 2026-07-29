<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ObservationLog;

final class ObservationLogTest
{
    #[Test]
    public function compressesRepeatsAndKeepsTheFirstAndLatestChanges(): void
    {
        $log = new ObservationLog(1.0);
        $log->record(0.5, 'same');
        $log->record(1.1, 'same');
        $log->record(1.2, 'first change');
        $log->record(1.3, 'second change');
        $log->record(1.4, 'third change');
        $log->record(1.5, 'fourth change');

        Expect::that($log->count())
            ->because('the observation count includes compressed and omitted values')
            ->toBe(6)
            ->and($log->render())
            ->because('the history keeps the first observation and the latest three value groups')
            ->toBe(
                "+0.0ms same (×2)\n"
                . "... 1 earlier changes omitted ...\n"
                . "+300.0ms second change\n"
                . "+400.0ms third change\n"
                . '+500.0ms fourth change',
            );
    }

    #[Test]
    public function compressesRepeatsInTheLatestChangedValue(): void
    {
        $log = new ObservationLog(0.0);
        $log->record(0.0, 'first');
        $log->record(0.001, 'changed');
        $log->record(0.002, 'changed');

        Expect::that($log->count())
            ->because('the observation count includes repeated tail values')
            ->toBe(3)
            ->and($log->render())
            ->because('consecutive tail values are one group with a repeat count')
            ->toBe("+0.0ms first\n+1.0ms changed (×2)");
    }

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
    public function renderedHistoryAtTheByteLimitIsNotTruncated(): void
    {
        $value = \str_repeat('x', 2_041);
        $log = new ObservationLog(0.0);
        $log->record(0.0, $value);

        Expect::that($log->render())
            ->because('history at the byte limit MUST remain unchanged')
            ->toBe('+0.0ms ' . $value);
    }

    #[Test]
    public function truncationDoesNotSplitAUnicodeCharacter(): void
    {
        $log = new ObservationLog(0.0);
        $log->record(0.0, \str_repeat('€', 1_000));

        Expect::that($log->render())
            ->because('bounded observation history MUST remain valid UTF-8')
            ->toBe('+0.0ms ' . \str_repeat('€', 679) . '...');
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
