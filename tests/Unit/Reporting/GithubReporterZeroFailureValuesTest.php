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

final class GithubReporterZeroFailureValuesTest
{
    #[Test]
    #[DataSet('zeroFailureValues')]
    public function annotationsRetainZeroFailureValues(
        ?string $expected,
        ?string $actual,
        string $diff,
    ): void {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);
        $result = new TestResult(
            new TestId('Acme\ZeroValueTest', 'checks'),
            Outcome::Failed,
            0.001,
            0,
            failures: [
                new FailureDetail('Values differ.', $expected, $actual),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('GitHub annotations MUST retain a falsey failure value when it is present')
            ->toBe('::error::Acme\ZeroValueTest::checks: Values differ.' . $diff . "\n");
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function zeroFailureValues(): iterable
    {
        yield 'expected' => ['0', null, '%0Aexpected: 0'];
        yield 'actual' => [null, '0', '%0Aactual: 0'];
    }
}
