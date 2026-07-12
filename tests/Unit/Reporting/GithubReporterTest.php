<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\GithubReporter;

final class GithubReporterTest
{
    #[Test]
    public function cannedStreamRendersOnlyFailureAndErrorCommands(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new GithubReporter($output));

        $expected = <<<'TXT'
            ::error file=/project/tests/CalculatorTest.php,line=42::Acme\CalculatorTest::subtractsIntegers: Failed asserting that two values are equal.%0Aexpected: 2%0Aactual: 3
            ::error file=/project/tests/NetworkTest.php,line=17::Acme\NetworkTest::connects: RuntimeException: Connection refused.
            TXT;

        Expect::that($output->buffer())->toBe($expected . "\n");
    }

    #[Test]
    public function messageAndPropertyValuesAreEscapedPerWorkflowCommandRules(): void
    {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);

        $result = new TestResult(
            new TestId('Acme\EscapeTest', 'escapes'),
            Outcome::Failed,
            0.001,
            0,
            failures: [
                new FailureDetail(
                    "50% done\nsecond line",
                    null,
                    null,
                    new SourceLocation('/project/tests/a:b,c.php', 3),
                ),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        Expect::that($output->buffer())->toBe(
            '::error file=/project/tests/a%3Ab%2Cc.php,line=3'
            . '::Acme\EscapeTest::escapes: 50%25 done%0Asecond line'
            . "\n",
        );
    }
}
