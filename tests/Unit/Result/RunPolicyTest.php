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
     * @param array{bool, int, int, int} $case
     */
    #[Test]
    #[DataSet('summaries')]
    public function skippedPolicyEvaluatesTheFinalSummaryWithoutChangingIt(array $case, bool $expected): void
    {
        [$failOnSkipped, $failed, $errored, $skipped] = $case;
        $summary = new ResultSummary(failed: $failed, errored: $errored, skipped: $skipped);

        Expect::that(new RunPolicy($failOnSkipped)->accepts($summary))
            ->because('the run policy MUST evaluate the final summary without changing test outcomes')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{array{bool, int, int, int}, bool}>
     */
    public static function summaries(): iterable
    {
        yield 'default accepts skipped tests' => [[false, 0, 0, 1], true];
        yield 'enabled accepts a clean summary' => [[true, 0, 0, 0], true];
        yield 'enabled rejects skipped tests' => [[true, 0, 0, 1], false];
        yield 'enabled rejects failed tests' => [[true, 1, 0, 0], false];
        yield 'enabled rejects errored tests' => [[true, 0, 1, 0], false];
    }
}
