<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Test\ExpectationCounter;

use function Greenlight\expect;

final class ExpectationCounterTest
{
    #[Test]
    public function nestedSuppressionPreservesTheValueAndResumesCounting(): void
    {
        ExpectationCounter::reset();
        ExpectationCounter::increment();
        $observed = [];

        $value = ExpectationCounter::withoutCounting(
            static function () use (&$observed): string {
                ExpectationCounter::increment();
                $observed[] = ExpectationCounter::count();

                return ExpectationCounter::withoutCounting(
                    static function () use (&$observed): string {
                        ExpectationCounter::increment();
                        $observed[] = ExpectationCounter::count();

                        return 'result';
                    },
                );
            },
        );
        $afterSuppression = ExpectationCounter::count();
        ExpectationCounter::increment();
        $afterResume = ExpectationCounter::count();

        expect($value)
            ->because('nested suppression MUST preserve the operation value')
            ->toBe('result');
        expect($observed)
            ->because('nested suppression MUST keep the expectation count unchanged')
            ->toBe([1, 1]);
        expect($afterSuppression)
            ->because('nested suppression MUST restore the earlier expectation count')
            ->toBe(1);
        expect($afterResume)
            ->because('expectation counting MUST resume after nested suppression')
            ->toBe(2);
    }

    #[Test]
    public function suppressionIsRestoredWhenTheOperationThrows(): void
    {
        ExpectationCounter::reset();

        expect()->calling(static fn(): mixed => ExpectationCounter::withoutCounting(
            static function (): never {
                ExpectationCounter::increment();

                throw new \RuntimeException('operation failed');
            },
        ))
            ->because('suppression MUST propagate an operation error')
            ->toThrow(\RuntimeException::class, message: 'operation failed');

        $afterThrow = ExpectationCounter::count();
        ExpectationCounter::increment();
        $afterResume = ExpectationCounter::count();

        expect($afterThrow)
            ->because('suppression MUST restore the earlier count after an operation error')
            ->toBe(1);
        expect($afterResume)
            ->because('expectation counting MUST resume after an operation error')
            ->toBe(2);
    }
}
