<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;

final class ResultSummaryTest
{
    #[Test]
    #[DataSet('negativeCounts')]
    public function negativeCountsAreRejected(string $field, string $message): void
    {
        $counts = [
            'passed' => 0,
            'failed' => 0,
            'errored' => 0,
            'skipped' => 0,
        ];
        $counts[$field] = -1;

        Expect::that(
            static fn(): ResultSummary => new ResultSummary(
                $counts['passed'],
                $counts['failed'],
                $counts['errored'],
                $counts['skipped'],
            ),
        )
            ->because('result summary counts MUST NOT be negative')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function negativeCounts(): iterable
    {
        foreach (['passed', 'failed', 'errored', 'skipped'] as $field) {
            yield $field => [
                $field,
                \sprintf('Result summary %s count cannot be negative.', $field),
            ];
        }
    }
}
