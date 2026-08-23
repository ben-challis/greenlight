<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;

/**
 * Produces shared plain text for a failed or errored result.
 *
 * render() writes expected and actual values for expectation failures. It
 * writes throwable details with the bounded stack. It also writes test
 * attempts and the transformation log.
 *
 * @internal
 */
final class ProblemDetails
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function render(TestResult $result): string
    {
        $lines = [];

        foreach ($result->failures as $failure) {
            $lines[] = '  ' . $failure->message;

            if ($failure->expected !== null) {
                $lines[] = '  expected: ' . $failure->expected;
            }

            if ($failure->actual !== null) {
                $lines[] = '  actual: ' . $failure->actual;
            }

            if ($failure->location !== null) {
                $lines[] = '  at ' . $failure->location;
            }
        }

        $error = $result->error;

        if ($error instanceof ThrowableDetail) {
            $lines[] = '  ' . $error->class . ': ' . $error->message;

            foreach ($error->stackFrames as $frame) {
                $lines[] = '    ' . $frame;
            }

            $lines[] = '  at ' . $error->file . ':' . $error->line;
        }

        if ($result->attempts > 1) {
            $lines[] = \sprintf('  after %d attempts', $result->attempts);
        }

        foreach ($result->transformations as $transformation) {
            $lines[] = \sprintf(
                '  outcome changed from %s to %s by %s',
                $transformation->from->value,
                $transformation->to->value,
                $transformation->transformedBy,
            );
        }

        $captured = $result->output;

        if ($captured instanceof CapturedOutput && ($captured->stdout !== '' || $captured->stdoutTruncated)) {
            $lines[] = '  captured standard output:';

            if ($captured->stdout !== '') {
                foreach (\explode("\n", \rtrim($captured->stdout, "\n")) as $capturedLine) {
                    $lines[] = '    ' . $capturedLine;
                }
            }

            if ($captured->stdoutTruncated) {
                $lines[] = '    (truncated)';
            }
        }

        if ($captured instanceof CapturedOutput && ($captured->stderr !== '' || $captured->stderrTruncated)) {
            $lines[] = '  captured standard error:';

            if ($captured->stderr !== '') {
                foreach (\explode("\n", \rtrim($captured->stderr, "\n")) as $capturedLine) {
                    $lines[] = '    ' . $capturedLine;
                }
            }

            if ($captured->stderrTruncated) {
                $lines[] = '    (truncated)';
            }
        }

        if ($captured instanceof CapturedOutput) {
            foreach ($captured->diagnostics as $diagnostic) {
                $lines[] = \sprintf(
                    '  %s: %s at %s:%d',
                    $diagnostic->severity->value,
                    $diagnostic->message,
                    $diagnostic->file,
                    $diagnostic->line,
                );
            }

            if ($captured->diagnosticsTruncated) {
                $lines[] = '  additional diagnostics omitted';
            }
        }

        if ($result->attachments !== []) {
            $lines = [
                ...$lines,
                ...\explode("\n", \rtrim(AttachmentFormat::render($result), "\n")),
            ];
        }

        if ($lines === []) {
            return '';
        }

        return \implode("\n", $lines) . "\n";
    }

    public static function outcomeLabel(TestResult $result): string
    {
        return match ($result->outcome) {
            Outcome::Passed => 'PASS',
            Outcome::Failed => 'FAIL',
            Outcome::Errored => 'ERROR',
            Outcome::Skipped => 'SKIP',
        };
    }
}
