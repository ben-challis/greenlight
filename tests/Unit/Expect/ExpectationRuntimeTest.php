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

        [$thrown, $afterInner] = ExpectationRuntime::withClock(
            $outer,
            static function () use ($inner, $expected): array {
                $thrown = null;

                try {
                    ExpectationRuntime::withClock(
                        $inner,
                        static fn(): never => throw $expected,
                    );
                } catch (\RuntimeException $caught) {
                    $thrown = $caught;
                }

                return [$thrown, ExpectationRuntime::clock()];
            },
        );

        Expect::that($thrown)
            ->because('withClock propagates the operation exception')
            ->toBe($expected);
        Expect::that($afterInner)
            ->toBe($outer);
        Expect::that(ExpectationRuntime::clock())
            ->toBe($baseline);
    }
}
