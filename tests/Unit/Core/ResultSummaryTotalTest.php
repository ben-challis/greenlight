<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Expect\Expect;

final readonly class ResultSummaryTotalTest
{
    #[Test]
    #[DataSet('outcomeCounts')]
    public function totalIncludesEveryOutcomeCount(ResultSummary $summary, int $expected): void
    {
        Expect::that($summary->total())
            ->because('the run total MUST include every outcome count')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{ResultSummary, non-negative-int}>
     */
    public static function outcomeCounts(): iterable
    {
        yield 'empty run' => [
            new ResultSummary(),
            0,
        ];
        yield 'passed tests' => [
            new ResultSummary(passed: 2),
            2,
        ];
        yield 'failed tests' => [
            new ResultSummary(failed: 3),
            3,
        ];
        yield 'errored tests' => [
            new ResultSummary(errored: 4),
            4,
        ];
        yield 'skipped tests' => [
            new ResultSummary(skipped: 5),
            5,
        ];
        yield 'mixed outcomes' => [
            new ResultSummary(passed: 2, failed: 3, errored: 4, skipped: 5),
            14,
        ];
    }
}
