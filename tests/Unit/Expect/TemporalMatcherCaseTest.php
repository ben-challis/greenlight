<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final class TemporalMatcherCaseTest
{
    #[Test]
    public function uppercaseMembershipReusesAnIterableAcrossRetries(): void
    {
        $clock = new FakeClock();
        $calls = 0;
        $haystack = (static function (): \Generator {
            yield 'ready';
        })();

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $haystack): void {
            expect()->calling(static function () use (&$calls): string {
                return ++$calls === 1 ? 'pending' : 'ready';
            })->returnValue()->eventually()->pollEvery(0.010)->within(0.100)->TOBEIN($haystack); // @phpstan-ignore method.nameCase (Checks PHP method case behavior.)
        });

        expect($calls)->toBe(2);
        expect($clock->sleeps)->toEqual([0.010]);
    }

    #[Test]
    public function uppercaseThrowableConstraintsFailBeforeTheProbeRuns(): void
    {
        $probed = false;
        expect()->calling(static function () use (&$probed): void {
            expect()->calling(static function () use (&$probed) { // @phpstan-ignore greenlight.toThrow.messageConstraint (Checks runtime validation.)
                $probed = true;

            })->eventually()->within(0.100)->TOTHROW(\RuntimeException::class, matching: '/x/', message: 'x'); // @phpstan-ignore method.nameCase (Checks PHP method case behavior.)
        })->toThrow(ExpectationFailed::class, matching: '/^Specify matching: or message:/');

        expect($probed)->toBeFalse();
    }
}
