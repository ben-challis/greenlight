<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final class EventuallyFinalProbeTest
{
    #[Test]
    public function eventuallyAcceptsAMatchFromTheProbeThatStartsAtTheDeadline(): void
    {
        $clock = new FakeClock();
        $values = ['pending', 'pending', 'ready'];
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use ($clock, &$calls, $values): void {
            expect()->calling(static function () use ($clock, &$calls, $values): string {
                $clock->time += 0.010;

                return $values[$calls++];
            })->returnValue()->eventually()
                ->pollEvery(0.050)
                ->within(0.100)
                ->toBe('ready');
        });

        expect($calls)
            ->because('the last probe starts at the deadline and its match MUST count')
            ->toBe(3);
    }

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
