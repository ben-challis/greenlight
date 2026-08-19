<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\TeamCityReporter;

final class CiReporterInvalidUtf8Test
{
    /** @param \Closure(BufferOutput): Reporter $create */
    #[Test]
    #[DataSet('reporters')]
    public function commandStreamsScrubInvalidUtf8(\Closure $create): void
    {
        $output = new BufferOutput();
        $reporter = $create($output);
        $result = new TestResult(
            new TestId('Acme\EncodingTest', 'reports'),
            Outcome::Failed,
            0.001,
            0,
            failures: [new FailureDetail("invalid \xFF byte")],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));

        Expect::that($output->buffer())
            ->because('a CI command stream MUST replace invalid UTF-8')
            ->toContain("invalid \u{FFFD} byte");
        Expect::that(\preg_match('//u', $output->buffer()))
            ->because('a CI command stream MUST contain only valid UTF-8')
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{\Closure(BufferOutput): Reporter}>
     */
    public static function reporters(): iterable
    {
        yield 'GitHub' => [static fn(BufferOutput $output): Reporter => new GithubReporter($output)];
        yield 'TeamCity' => [static fn(BufferOutput $output): Reporter => new TeamCityReporter($output)];
    }
}
