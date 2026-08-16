<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;

final class TestResultExpectationValidationTest
{
    #[Test]
    public function expectationCountsStartAtZero(): void
    {
        $id = new TestId('Example\\ExpectationTest', 'counts');

        Expect::that(new TestResult($id, Outcome::Passed, 0.1, 0, expectations: 0)->expectations)
            ->because('a result MAY contain no verified expectations')
            ->toBe(0);
        Expect::that(static fn(): TestResult => new TestResult(
            $id,
            Outcome::Passed,
            0.1,
            0,
            expectations: -1,
        ))
            ->because('a result MUST NOT contain a negative expectation count')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Expectation count MUST NOT be negative.',
            );
    }
}
