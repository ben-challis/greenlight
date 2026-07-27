<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\StalledPollingClock;

final class PollingClockStallTest
{
    #[Test]
    public function aStalledClockCannotCauseAnUnlimitedWait(): void
    {
        Expect::that(static function (): void {
            ExpectationRuntime::withClock(
                new StalledPollingClock(),
                static fn() => Expect::eventually(static fn(): string => 'pending')
                    ->pollEvery(0.010)
                    ->within(0.100)
                    ->toBe('ready'),
            );
        })
            ->because('a stalled polling clock MUST fail instead of waiting without a limit')
            ->toThrow(
                \LogicException::class,
                message: 'The polling clock did not advance during sleep.',
            );
    }
}
