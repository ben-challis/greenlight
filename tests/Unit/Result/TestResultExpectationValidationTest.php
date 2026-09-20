<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final class TestResultExpectationValidationTest
{
    #[Test]
    public function expectationCountsStartAtZero(): void
    {
        $id = new TestId('Example\\ExpectationTest', 'counts');

        expect(new TestResult($id, Outcome::Passed, 0.1, 0, expectations: 0)->expectations)
            ->because('a result MAY contain no verified expectations')
            ->toBe(0);
        expect()->calling(static fn(): TestResult => new TestResult(
            $id,
            Outcome::Passed,
            0.1,
            0,
            expectations: -1,
        ))
            ->because('a result MUST NOT contain a negative expectation count')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Expectation count cannot be negative.',
            );
    }
}
