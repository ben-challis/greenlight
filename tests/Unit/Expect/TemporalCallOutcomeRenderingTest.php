<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final class TemporalCallOutcomeRenderingTest
{
    #[Test]
    public function eventuallyCallFailureRendersEachCallOutcome(): void
    {
        $clock = new FakeClock();
        $detail = ExpectationRuntime::withClock($clock, static fn() => FailureProbe::detailOf(
            static fn() => expect()->calling(static fn() => throw new \RuntimeException('not yet'))
                ->eventually()
                ->pollEvery(0.010)
                ->within(0.020)
                ->toThrow(\LogicException::class),
        ));

        expect($detail->message)
            ->because('the observation log MUST render the thrown class of a call outcome')
            ->toContain("threw RuntimeException with message 'not yet'");
        expect($detail->message)
            ->because('the observation log MUST NOT render the replay closure')
            ->not()->toContain('unrendered');

        $detail = ExpectationRuntime::withClock($clock, static fn() => FailureProbe::detailOf(
            static fn() => expect()->calling(static fn(): int => 7)
                ->eventually()
                ->pollEvery(0.010)
                ->within(0.020)
                ->toReturn(8),
        ));

        expect($detail->message)
            ->because('the observation log MUST render the return value of a call outcome')
            ->toContain('returned 7');
    }
}
