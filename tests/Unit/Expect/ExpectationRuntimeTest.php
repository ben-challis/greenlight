<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final class ExpectationRuntimeTest
{
    #[Test]
    public function nestedClockIsRestoredAfterTheOperationThrows(): void
    {
        $baseline = ExpectationRuntime::clock();
        $outer = new FakeClock();
        $inner = new FakeClock();
        $expected = new \RuntimeException('clock operation failed');

        ExpectationRuntime::withClock(
            $outer,
            static function () use ($inner, $outer, $expected): void {
                expect()->calling(static fn(): mixed => ExpectationRuntime::withClock(
                    $inner,
                    static fn(): never => throw $expected,
                ))
                    ->because('withClock propagates the operation exception')
                    ->toThrow($expected);

                expect(ExpectationRuntime::clock())
                    ->toBe($outer);
            },
        );

        expect(ExpectationRuntime::clock())
            ->toBe($baseline);
    }
}
