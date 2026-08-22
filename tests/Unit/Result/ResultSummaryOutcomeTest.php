<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;

final class ResultSummaryOutcomeTest
{
    /**
     * @param array{passed: int, failed: int, errored: int, skipped: int} $expected
     */
    #[Test]
    #[DataSet('outcomes')]
    public function addIncrementsOnlyTheSelectedOutcome(Outcome $outcome, array $expected): void
    {
        $original = new ResultSummary(passed: 1, failed: 2, errored: 3, skipped: 4);

        Expect::that($original->add($outcome)->toWire())
            ->because('adding an outcome MUST increment only its matching summary count')
            ->toBe($expected);
        Expect::that($original->toWire())
            ->because('adding an outcome MUST leave the original summary unchanged')
            ->toBe([
                'passed' => 1,
                'failed' => 2,
                'errored' => 3,
                'skipped' => 4,
            ]);
    }

    /**
     * @return iterable<string, array{Outcome, array{passed: int, failed: int, errored: int, skipped: int}}>
     */
    public static function outcomes(): iterable
    {
        yield 'passed' => [
            Outcome::Passed,
            ['passed' => 2, 'failed' => 2, 'errored' => 3, 'skipped' => 4],
        ];
        yield 'failed' => [
            Outcome::Failed,
            ['passed' => 1, 'failed' => 3, 'errored' => 3, 'skipped' => 4],
        ];
        yield 'errored' => [
            Outcome::Errored,
            ['passed' => 1, 'failed' => 2, 'errored' => 4, 'skipped' => 4],
        ];
        yield 'skipped' => [
            Outcome::Skipped,
            ['passed' => 1, 'failed' => 2, 'errored' => 3, 'skipped' => 5],
        ];
    }

    #[Test]
    public function addSaturatesTheSelectedOutcomeAtTheMachineMaximum(): void
    {
        $summary = new ResultSummary(passed: \PHP_INT_MAX);

        Expect::that($summary->add(Outcome::Passed)->passed)
            ->because('adding an outcome MUST NOT overflow its summary count')
            ->toBe(\PHP_INT_MAX);
    }
}
