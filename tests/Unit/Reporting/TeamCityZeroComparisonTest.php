<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final class TeamCityZeroComparisonTest
{
    #[Test]
    #[DataSet('zeroValues')]
    public function zeroComparisonValuesRemainStructured(
        string $expected,
        string $actual,
        string $attributes,
    ): void {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $result = new TestResult(
            new TestId('Acme\\ZeroTest', 'comparesValues'),
            Outcome::Failed,
            0.001,
            1,
            failures: [new FailureDetail('values differ', $expected, $actual)],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));

        Expect::that($output->buffer())
            ->because('TeamCity comparison metadata MUST preserve the string "0"')
            ->toContain($attributes);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function zeroValues(): iterable
    {
        yield 'expected value is zero' => [
            '0',
            'right',
            "type='comparisonFailure' expected='0' actual='right'",
        ];
        yield 'actual value is zero' => [
            'left',
            '0',
            "type='comparisonFailure' expected='left' actual='0'",
        ];
    }
}
