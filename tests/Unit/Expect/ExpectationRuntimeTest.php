<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final class ExpectationRuntimeTest
{
    #[Test]
    public function nestedClockIsRestoredAfterTheOperationThrows(): void
    {
        $baseline = ExpectationRuntime::clock();
        $outer = new FakePollingClock();
        $inner = new FakePollingClock();
        $expected = new \RuntimeException('clock operation failed');

        ExpectationRuntime::withClock(
            $outer,
            static function () use ($inner, $outer, $expected): void {
                Expect::that(static fn(): mixed => ExpectationRuntime::withClock(
                    $inner,
                    static fn(): never => throw $expected,
                ))
                    ->because('withClock propagates the operation exception')
                    ->toThrow(
                        static function (\RuntimeException $caught) use ($expected): void {
                            Expect::that($caught)->toBe($expected);
                        },
                    );

                Expect::that(ExpectationRuntime::clock())
                    ->toBe($outer);
            },
        );

        Expect::that(ExpectationRuntime::clock())
            ->toBe($baseline);
    }
}
