<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;

final readonly class DoneValidationTest
{
    #[Test]
    #[DataSet('negativeMemoryMeasurements')]
    public function directMessagesRejectNegativePeakMemory(int $peakMemoryBytes): void
    {
        Expect::that(static fn(): Done => new Done(new ResultSummary(), $peakMemoryBytes))
            ->because('worker completion messages MUST NOT report negative peak memory')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Done message peak memory cannot be negative. Actual value: %d.',
                    $peakMemoryBytes,
                ),
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function negativeMemoryMeasurements(): iterable
    {
        yield 'minus one' => [-1];
        yield 'minimum integer' => [\PHP_INT_MIN];
    }
}
