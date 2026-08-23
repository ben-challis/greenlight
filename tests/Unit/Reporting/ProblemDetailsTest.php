<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProblemDetails;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutcomeTransformation;
use Greenlight\Result\SourceLocation;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;

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
            output: new CapturedOutput(
                "first line\nsecond line\n",
                stdoutTruncated: true,
                stderr: "error line\n",
                stderrTruncated: true,
            ),
        );

        $expected = <<<'TXT'
              after 3 attempts
              outcome changed from passed to failed by quarantine
              captured standard output:
                first line
                second line
                (truncated)
              captured standard error:
                error line
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
              captured standard output:
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
    public function truncatedDiagnosticsReportTheOmittedEntries(): void
    {
        $result = new TestResult(
            new TestId('Acme\\FailureTest', 'reportsTruncatedDiagnostics'),
            Outcome::Failed,
            0.1,
            0,
            output: new CapturedOutput(
                '',
                diagnostics: [
                    new Diagnostic(DiagnosticSeverity::Warning, 'first warning', 'FailureTest.php', 13),
                ],
                diagnosticsTruncated: true,
            ),
        );

        Expect::that(ProblemDetails::render($result))
            ->because('bounded diagnostics MUST report omitted entries')
            ->toBe(
                "  warning: first warning at FailureTest.php:13\n"
                . "  additional diagnostics omitted\n",
            );
    }

    #[Test]
    public function emptyTruncatedStreamsStillReportTheirLimits(): void
    {
        $result = new TestResult(
            new TestId('Acme\\FailureTest', 'reportsEmptyTruncatedStreams'),
            Outcome::Failed,
            0.1,
            0,
            output: new CapturedOutput('', stdoutTruncated: true, stderrTruncated: true),
        );

        Expect::that(ProblemDetails::render($result))->toBe(
            "  captured standard output:\n"
            . "    (truncated)\n"
            . "  captured standard error:\n"
            . "    (truncated)\n",
        );
    }

    #[Test]
    #[DataSet('failureDiffs')]
    public function failureDiffsRetainEveryPresentValue(
        ?string $expected,
        ?string $actual,
        string $detail,
    ): void {
        $result = new TestResult(
            new TestId('Acme\\FailureTest', 'reportsPartialDiff'),
            Outcome::Failed,
            0.1,
            0,
            failures: [
                new FailureDetail('values differ', expected: $expected, actual: $actual),
            ],
        );

        Expect::that(ProblemDetails::render($result))
            ->because('shared problem details MUST retain each present diff value independently')
            ->toBe("  values differ\n  {$detail}\n");
    }

    #[Test]
    public function multipleFailureDetailsRenderCompletelyInOrder(): void
    {
        $result = new TestResult(
            new TestId('Acme\\FailureTest', 'failsMoreThanOnce'),
            Outcome::Failed,
            0.1,
            0,
            failures: [
                new FailureDetail('first mismatch', expected: 'alpha', actual: 'beta'),
                new FailureDetail(
                    'second mismatch',
                    location: new SourceLocation('FailureTest.php', 24),
                ),
            ],
        );

        $expected = <<<'TXT'
              first mismatch
              expected: alpha
              actual: beta
              second mismatch
              at FailureTest.php:24
            TXT;

        Expect::that(ProblemDetails::render($result))
            ->because('shared problem details MUST retain multiple failures in encounter order')
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
     * @return iterable<string, array{?string, ?string, non-empty-string}>
     */
    public static function failureDiffs(): iterable
    {
        yield 'expected only' => ['left', null, 'expected: left'];

        yield 'actual only' => [null, 'right', 'actual: right'];

        yield 'zero expected' => ['0', '1', "expected: 0\n  actual: 1"];

        yield 'zero actual' => ['1', '0', "expected: 1\n  actual: 0"];
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
