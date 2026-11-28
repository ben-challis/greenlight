<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;
use Greenlight\Tests\Fixture\Expect\OvershootingClock;

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
    public function eventuallyAcceptsTheLastProbeWhenTheSleepOvershoots(): void
    {
        $clock = new OvershootingClock(0.000_005);
        $values = ['pending', 'pending', 'pending', 'ready'];
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $values): void {
            expect()->calling(static function () use (&$calls, $values): string {
                return $values[$calls++];
            })->returnValue()->eventually()
                ->pollEvery(0.040)
                ->within(0.100)
                ->toBe('ready');
        });

        expect($calls)
            ->because('the probe after the last sleep starts after the deadline, and its match MUST count')
            ->toBe(4);
    }

    #[Test]
    public function eventuallyAcceptsTheLastProbeWithTheSystemClock(): void
    {
        $startedAt = \hrtime(true);

        expect()->calling(static function () use ($startedAt): string {
            $elapsed = (\hrtime(true) - $startedAt) / 1e9;

            return $elapsed >= 0.095 ? 'ready' : 'pending';
        })->returnValue()->eventually()
            ->pollEvery(0.030)
            ->within(0.100)
            ->because('the probe at the deadline MUST count although a real sleep overshoots the deadline')
            ->toBe('ready');
    }
}
