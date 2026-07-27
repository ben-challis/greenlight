<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\OutcomeTransformation;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProblemDetails;

final class ProblemDetailsTest
{
    #[Test]
    public function retryAndTransformationContextPrecedesCapturedOutput(): void
    {
        $result = new TestResult(
            new TestId('Acme\FlakyTest', 'eventuallyPasses'),
            Outcome::Failed,
            0.1,
            0,
            attempts: 3,
            transformations: [
                new OutcomeTransformation('quarantine', Outcome::Passed, Outcome::Failed),
            ],
            output: new CapturedOutput("first line\nsecond line\n", stdoutTruncated: true),
        );

        $expected = <<<'TXT'
              after 3 attempts
              outcome changed from passed to failed by quarantine
              captured output:
                first line
                second line
                (truncated)
            TXT;

        Expect::that(ProblemDetails::render($result))
            ->because('problem context renders in diagnostic order')
            ->toBe($expected . "\n");
    }
}
