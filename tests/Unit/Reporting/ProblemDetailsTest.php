<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\OutcomeTransformation;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
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

    #[Test]
    public function failureDetailsCapturedDiagnosticsAndAttachmentsRenderExactly(): void
    {
        $result = new TestResult(
            new TestId('Acme\\FailureTest', 'fails'),
            Outcome::Failed,
            0.1,
            0,
            failures: [
                new FailureDetail(
                    'values differ',
                    expected: '42',
                    actual: '41',
                    location: new SourceLocation('FailureTest.php', 12),
                ),
            ],
            output: new CapturedOutput(
                'captured',
                [new Diagnostic(DiagnosticSeverity::Warning, 'careful', 'FailureTest.php', 13)],
            ),
            attachments: [
                new Attachment(
                    'log',
                    AttachmentKind::Text,
                    'text/plain',
                    3,
                    \str_repeat('a', 64),
                    1,
                    'run/log.txt',
                ),
            ],
        );

        $expected = <<<'TXT'
              values differ
              expected: 42
              actual: 41
              at FailureTest.php:12
              captured output:
                captured
              warning: careful at FailureTest.php:13
              attachments:
                log (text/plain, 3 bytes): run/log.txt
            TXT;

        Expect::that(ProblemDetails::render($result))
            ->because('shared problem details MUST retain every failure diagnostic')
            ->toBe($expected . "\n");
    }

    #[Test]
    public function throwableDetailsRenderWithTheirStack(): void
    {
        $result = new TestResult(
            new TestId('Acme\\ErrorTest', 'errors'),
            Outcome::Errored,
            0.1,
            0,
            error: new ThrowableDetail(
                \RuntimeException::class,
                'boom',
                'ErrorTest.php',
                9,
                ['call at ErrorTest.php:7'],
            ),
        );

        $expected = <<<'TXT'
              RuntimeException: boom
                call at ErrorTest.php:7
              at ErrorTest.php:9
            TXT;

        Expect::that(ProblemDetails::render($result))
            ->because('shared problem details MUST retain throwable context')
            ->toBe($expected . "\n");
    }

    #[Test]
    public function aResultWithoutProblemDetailsRendersNothing(): void
    {
        $result = new TestResult(
            new TestId('Acme\\PassingTest', 'passes'),
            Outcome::Passed,
            0.1,
            0,
        );

        Expect::that(ProblemDetails::render($result))
            ->because('a result without problem details MUST render an empty string')
            ->toBe('');
    }

    #[Test]
    #[DataSet('outcomeLabels')]
    public function outcomeLabelsAreStable(Outcome $outcome, string $label): void
    {
        $result = new TestResult(
            new TestId('Acme\\OutcomeTest', 'reports'),
            $outcome,
            0.1,
            0,
        );

        Expect::that(ProblemDetails::outcomeLabel($result))
            ->because('reporters MUST use the stable outcome label')
            ->toBe($label);
    }

    /**
     * @return iterable<string, array{Outcome, non-empty-string}>
     */
    public static function outcomeLabels(): iterable
    {
        yield 'passed' => [Outcome::Passed, 'PASS'];
        yield 'failed' => [Outcome::Failed, 'FAIL'];
        yield 'errored' => [Outcome::Errored, 'ERROR'];
        yield 'skipped' => [Outcome::Skipped, 'SKIP'];
    }
}
