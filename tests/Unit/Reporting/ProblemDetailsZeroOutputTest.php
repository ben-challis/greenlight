<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProblemDetails;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final class ProblemDetailsZeroOutputTest
{
    #[Test]
    public function zeroCapturedOutputIsNotTreatedAsEmpty(): void
    {
        $result = new TestResult(
            new TestId('Acme\\OutputTest', 'printsZero'),
            Outcome::Failed,
            0.1,
            0,
            output: new CapturedOutput('0'),
        );

        Expect::that(ProblemDetails::render($result))
            ->because('captured output MUST preserve the string "0"')
            ->toBe("  captured standard output:\n    0\n");
    }
}
