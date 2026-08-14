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
use Greenlight\Core\Result\ThrowableDetail;
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
    public function everyFailureDetailProducesItsOwnLocatedAnnotation(): void
    {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);
        $result = new TestResult(
            new TestId('Acme\MultiFailureTest', 'checks'),
            Outcome::Failed,
            0.001,
            0,
            failures: [
                new FailureDetail(
                    "first 50%\nline",
                    '1',
                    '2',
                    new SourceLocation('/project/tests/First:Case.php', 11),
                ),
                new FailureDetail(
                    "second\rproblem",
                    'left',
                    'right',
                    new SourceLocation('/project/tests/Second,Case.php', 12),
                ),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('each failure detail MUST retain its location, diff, and workflow-command escaping')
            ->toBe(
                '::error file=/project/tests/First%3ACase.php,line=11'
                . '::Acme\MultiFailureTest::checks: first 50%25%0Aline%0Aexpected: 1%0Aactual: 2'
                . "\n"
                . '::error file=/project/tests/Second%2CCase.php,line=12'
                . '::Acme\MultiFailureTest::checks: second%0Dproblem%0Aexpected: left%0Aactual: right'
                . "\n",
            );
    }

    #[Test]
    #[DataSet('failureDiffs')]
    public function failureDiffsRetainEachAvailableSide(
        ?string $expected,
        ?string $actual,
        string $diff,
    ): void {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);
        $result = new TestResult(
            new TestId('Acme\PartialDiffTest', 'reports'),
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
            ->because('a GitHub annotation MUST retain each available diff side independently')
            ->toBe('::error::Acme\PartialDiffTest::reports: Values differ.' . $diff . "\n");
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

    #[Test]
    public function structuredErrorsIncludeAttachmentPathsInTheAnnotation(): void
    {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);
        $result = new TestResult(
            new TestId('Acme\NetworkTest', 'connects'),
            Outcome::Errored,
            0.001,
            0,
            error: new ThrowableDetail(
                \RuntimeException::class,
                'Connection refused.',
                '/project/tests/NetworkTest.php',
                17,
            ),
            attachments: [
                new Attachment(
                    'request.log',
                    AttachmentKind::Text,
                    'text/plain',
                    8,
                    \str_repeat('a', 64),
                    1,
                    'build/request.log',
                ),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('structured error annotations retain attachment paths')
            ->toBe(
                '::error file=/project/tests/NetworkTest.php,line=17'
                . '::Acme\NetworkTest::connects: RuntimeException: Connection refused.'
                . '%0Aattachments:%0Arequest.log: build/request.log'
                . "\n",
            );
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function failureDiffs(): iterable
    {
        yield 'expected only' => ['left', null, '%0Aexpected: left'];

        yield 'actual only' => [null, 'right', '%0Aactual: right'];

        yield 'zero expected only' => ['0', null, '%0Aexpected: 0'];

        yield 'zero actual only' => [null, '0', '%0Aactual: 0'];
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
