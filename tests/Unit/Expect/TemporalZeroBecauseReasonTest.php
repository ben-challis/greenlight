<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final readonly class TemporalZeroBecauseReasonTest
{
    #[Test]
    public function temporalExpectationRetainsAZeroReason(): void
    {
        $clock = new FakePollingClock();
        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::consistently(static fn(): bool => false)
                ->for(1.000)
                ->because('0')
                ->toBeTrue(),
        ));

        Expect::that($detail->message)
            ->because('a temporal expectation MUST retain a zero reason')
            ->toBe(
                'The consistently() expectation failed on the first observation. '
                . 'Last failure: Expected false to be true because 0. '
                . 'Observations: +0.0ms false.',
            );
        Expect::that($clock->sleeps)
            ->because('a first-observation failure MUST NOT wait')
            ->toBe([]);
    }
}
