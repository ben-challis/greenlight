<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;

/**
 * Shared plain-text rendering of a failing or errored result: expectation
 * failures with expected and actual, throwable details with the bounded
 * stack, retry attempts, and outcome transformation provenance.
 *
 * @internal
 */
final class ProblemDetails
{
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

        if ($captured instanceof CapturedOutput && $captured->stdout !== '') {
            $lines[] = '  captured output:';

            foreach (\explode("\n", \rtrim($captured->stdout, "\n")) as $capturedLine) {
                $lines[] = '    ' . $capturedLine;
            }

            if ($captured->stdoutTruncated) {
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
