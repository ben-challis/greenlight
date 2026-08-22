<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;
use Greenlight\Runner\Protocol\Messages\Done;

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
                    'Done message peak memory MUST NOT be negative. Actual value: %d.',
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
