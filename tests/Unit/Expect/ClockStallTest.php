<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Expect\SystemClock;
use Greenlight\Tests\Fixture\Expect\StalledClock;

use function Greenlight\expect;

final class ClockStallTest
{
    #[Test]
    public function aStalledClockCannotCauseAnUnlimitedWait(): void
    {
        expect()->calling(static function (): void {
            $stalled = new StalledClock();

            ExpectationRuntime::withClock(
                new SystemClock($stalled->sleep(...), $stalled->now(...)),
                static fn() => expect()->calling(static fn(): string => 'pending')->returnValue()->eventually()
                    ->pollEvery(0.010)
                    ->within(0.100)
                    ->toBe('ready'),
            );
        })
            ->because('a stalled clock MUST fail instead of waiting without a limit')
            ->toThrow(
                \LogicException::class,
                message: 'The clock did not advance during sleep.',
            );
    }
}
