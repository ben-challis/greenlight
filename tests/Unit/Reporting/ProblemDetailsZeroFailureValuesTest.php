<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProblemDetails;

final class ProblemDetailsZeroFailureValuesTest
{
    #[Test]
    #[DataSet('zeroValues')]
    public function zeroFailureValuesRemainVisible(
        string $expected,
        string $actual,
        string $rendered,
    ): void {
        $result = new TestResult(
            new TestId('Acme\\ZeroTest', 'comparesValues'),
            Outcome::Failed,
            0.1,
            1,
            failures: [new FailureDetail('values differ', $expected, $actual)],
        );

        Expect::that(ProblemDetails::render($result))
            ->because('failure details MUST preserve the string "0"')
            ->toBe($rendered);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function zeroValues(): iterable
    {
        yield 'expected value is zero' => [
            '0',
            '1',
            "  values differ\n  expected: 0\n  actual: 1\n",
        ];
        yield 'actual value is zero' => [
            '1',
            '0',
            "  values differ\n  expected: 1\n  actual: 0\n",
        ];
    }
}
