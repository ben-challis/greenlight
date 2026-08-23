<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\RunPolicy;

final readonly class RunPolicyTest
{
    /**
     * @param array{bool, bool, non-negative-int, non-negative-int, non-negative-int, non-negative-int} $case
     */
    #[Test]
    #[DataSet('summaries')]
    public function skippedPolicyEvaluatesTheFinalSummaryWithoutChangingIt(array $case, bool $expected): void
    {
        [$failOnSkipped, $failOnRetriedPass, $failed, $errored, $skipped, $retriedPasses] = $case;
        $summary = new ResultSummary(failed: $failed, errored: $errored, skipped: $skipped);

        Expect::that(new RunPolicy($failOnSkipped, $failOnRetriedPass)->accepts($summary, $retriedPasses))
            ->because('the run policy MUST evaluate the final summary without changing test outcomes')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{array{bool, bool, non-negative-int, non-negative-int, non-negative-int, non-negative-int}, bool}>
     */
    public static function summaries(): iterable
    {
        yield 'default accepts skipped tests and retried passes' => [[false, false, 0, 0, 1, 2], true];
        yield 'enabled accepts a clean summary' => [[true, true, 0, 0, 0, 0], true];
        yield 'enabled rejects skipped tests' => [[true, false, 0, 0, 1, 0], false];
        yield 'enabled rejects retried passes' => [[false, true, 0, 0, 0, 1], false];
        yield 'enabled rejects failed tests' => [[true, true, 1, 0, 0, 0], false];
        yield 'enabled rejects errored tests' => [[true, true, 0, 1, 0, 0], false];
    }
}
