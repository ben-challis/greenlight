<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class RetryTimesValidationTest
{
    #[Test]
    #[DataSet('nonPositiveTimes')]
    public function rejectsNonPositiveTimes(int $times): void
    {
        Expect::that(static fn(): Retry => new Retry($times))
            ->because('retry times MUST be positive')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Retry times must be at least 1.',
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveTimes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }
}
