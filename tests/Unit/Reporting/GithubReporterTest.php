<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
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

        Expect::that($output->buffer())->because('canned stream renders only failure and error commands')->toBe($expected . "\n");
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

        Expect::that($output->buffer())->because('message and property values are escaped per workflow command rules')->toBe(
            '::error file=/project/tests/a%3Ab%2Cc.php,line=3'
            . '::Acme\EscapeTest::escapes: 50%25 done%0Asecond line'
            . "\n",
        );
    }

    #[Test]
    #[DataSet('outcomesWithoutDetails')]
    public function outcomesWithoutDetailsStillProduceAnnotations(Outcome $outcome, string $summary): void
    {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);
        $result = new TestResult(
            new TestId('Acme\FallbackTest', 'reports'),
            $outcome,
            0.001,
            0,
            attachments: [
                new Attachment(
                    'evidence.txt',
                    AttachmentKind::Text,
                    'text/plain',
                    8,
                    \str_repeat('a', 64),
                    1,
                    'build/evidence.txt',
                ),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('an outcome without structured details still produces an annotation')
            ->toBe(
                '::error::Acme\FallbackTest::reports: ' . $summary
                . '.%0Aattachments:%0Aevidence.txt: build/evidence.txt'
                . "\n",
            );
    }

    /**
     * @return iterable<string, array{Outcome, string}>
     */
    public static function outcomesWithoutDetails(): iterable
    {
        yield 'failed' => [Outcome::Failed, 'failed'];

        yield 'errored' => [Outcome::Errored, 'errored'];
    }
}
