<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final readonly class TemporalRetryInheritanceTest
{
    #[Test]
    public function retryableParentExceptionIncludesItsSubclasses(): void
    {
        $calls = 0;

        ExpectationRuntime::withClock(
            new FakePollingClock(),
            static function () use (&$calls): void {
                Expect::eventually(static function () use (&$calls): string {
                    if (++$calls === 1) {
                        throw new \RuntimeException('not ready');
                    }

                    return 'ready';
                })
                    ->retryOnException(\Exception::class)
                    ->pollEvery(0.010)
                    ->within(0.100)
                    ->toBe('ready');
            },
        );

        Expect::that($calls)
            ->because('a retryable parent exception MUST include each subclass')
            ->toBe(2);
    }
}
