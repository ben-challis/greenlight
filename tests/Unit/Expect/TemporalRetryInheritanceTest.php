<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final readonly class TemporalRetryInheritanceTest
{
    #[Test]
    public function retryableParentExceptionIncludesItsSubclasses(): void
    {
        $calls = 0;

        ExpectationRuntime::withClock(
            new FakeClock(),
            static function () use (&$calls): void {
                expect()->calling(static function () use (&$calls): string {
                    if (++$calls === 1) {
                        throw new \RuntimeException('not ready');
                    }

                    return 'ready';
                })->returnValue()->eventually()
                    ->retryOnException(\Exception::class)
                    ->pollEvery(0.010)
                    ->within(0.100)
                    ->toBe('ready');
            },
        );

        expect($calls)
            ->because('a retryable parent exception MUST include each subclass')
            ->toBe(2);
    }
}
