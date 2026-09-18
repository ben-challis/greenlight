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
                Expect::calling(static fn(): mixed => ExpectationRuntime::withClock(
                    $inner,
                    static fn(): never => throw $expected,
                ))
                    ->because('withClock propagates the operation exception')
                    ->toThrow($expected);

                Expect::value(ExpectationRuntime::clock())
                    ->toBe($outer);
            },
        );

        Expect::value(ExpectationRuntime::clock())
            ->toBe($baseline);
    }
}
