<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final class TemporalMatcherCaseTest
{
    #[Test]
    public function uppercaseMembershipReusesAnIterableAcrossRetries(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;
        $haystack = (static function (): \Generator {
            yield 'ready';
        })();

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $haystack): void {
            Expect::eventually(static function () use (&$calls): string {
                return ++$calls === 1 ? 'pending' : 'ready';
            })->pollEvery(0.010)->within(0.100)->__call('TOBEIN', [$haystack]);
        });

        Expect::that($calls)->toBe(2);
        Expect::that($clock->sleeps)->toEqual([0.010]);
    }

    #[Test]
    public function uppercaseThrowableConstraintsFailBeforeTheProbeRuns(): void
    {
        $probed = false;
        Expect::that(static function () use (&$probed): void {
            Expect::eventually(static function () use (&$probed): \Closure {
                $probed = true;

                return static function (): void {};
            })->within(0.100)->__call('TOTHROW', [\RuntimeException::class, 'matching' => '/x/', 'message' => 'x']);
        })->toThrow(ExpectationFailed::class, matching: '/^Specify matching: or message:/');

        Expect::that($probed)->toBeFalse();
    }
}
