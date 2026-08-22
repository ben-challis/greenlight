<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;

final readonly class ResultSummarySuccessTest
{
    #[Test]
    #[DataSet('runOutcomes')]
    public function successRequiresNoFailedOrErroredTests(
        ResultSummary $summary,
        bool $expected,
    ): void {
        Expect::that($summary->isSuccessful())
            ->because('run success MUST depend on failed and errored counts only')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{ResultSummary, bool}>
     */
    public static function runOutcomes(): iterable
    {
        yield 'empty run' => [
            new ResultSummary(),
            true,
        ];
        yield 'passed tests' => [
            new ResultSummary(passed: 2),
            true,
        ];
        yield 'skipped tests' => [
            new ResultSummary(skipped: 2),
            true,
        ];
        yield 'failed test' => [
            new ResultSummary(failed: 1),
            false,
        ];
        yield 'errored test' => [
            new ResultSummary(errored: 1),
            false,
        ];
    }
}
