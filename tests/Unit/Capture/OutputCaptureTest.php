<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Capture\CaptureError;
use Greenlight\Capture\OutputCapture;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Expect\Expect;

final class OutputCaptureTest
{
    #[Test]
    public function echoInsideTheWindowIsCapturedAndDoesNotReachTheOuterStream(): void
    {
        \ob_start();

        try {
            $capture = new OutputCapture();
            $capture->start();
            echo 'hello from the test';
            $captured = $capture->stop();

            $leaked = \ob_get_contents();
        } finally {
            \ob_end_clean();
        }

        Expect::that($captured->stdout)->because('echo inside the window is captured and does not reach the outer stream')->toBe('hello from the test')
            ->and($captured->stdoutTruncated)->toBeFalse()
            ->and($leaked)->toBe('');
    }

    #[Test]
    public function stopRestoresTheBufferStackToItsBaseline(): void
    {
        $baseline = \ob_get_level();

        $capture = new OutputCapture();
        $capture->start();
        echo 'x';
        $capture->stop();

        Expect::that(\ob_get_level())->because('stop restores the buffer stack to its baseline')->toBe($baseline);
    }

    #[Test]
    public function userCodeNestingItsOwnOutputBuffersKeepsWorking(): void
    {
        $capture = new OutputCapture();
        $capture->start();

        echo 'a';
        \ob_start();
        echo 'inner';
        $inner = \ob_get_clean();
        echo 'b';

        $captured = $capture->stop();

        Expect::that($inner)->because('user code nesting its own output buffers keeps working')->toBe('inner')
            ->and($captured->stdout)->toBe('ab');
    }

    #[Test]
    public function aUserBufferLeftOpenIsFlushedIntoTheCapture(): void
    {
        $baseline = \ob_get_level();

        $capture = new OutputCapture();
        $capture->start();

        echo 'head ';
        \ob_start();
        echo 'leftover';

        $captured = $capture->stop();

        Expect::that($captured->stdout)->because('a user buffer left open is flushed into the capture')->toBe('head leftover')
            ->and(\ob_get_level())->toBe($baseline);
    }

    #[Test]
    public function noticesWarningsAndDeprecationsAreRecordedWithFileAndLine(): void
    {
        $capture = new OutputCapture();
        $capture->start();

        \trigger_error('a notice', \E_USER_NOTICE);
        \trigger_error('a warning', \E_USER_WARNING);
        \trigger_error('a deprecation', \E_USER_DEPRECATED);

        $captured = $capture->stop();

        Expect::that($captured->diagnostics)->because('notices warnings and deprecations are recorded with file and line')->toHaveCount(3)
            ->and($captured->stdout)->toBe('');

        [$notice, $warning, $deprecation] = $captured->diagnostics;

        Expect::that($notice->severity)->because('notices warnings and deprecations are recorded with file and line')->toBe(DiagnosticSeverity::Notice)
            ->and($notice->message)->toBe('a notice')
            ->and($notice->file)->toBe(__FILE__)
            ->and($notice->line)->toBeGreaterThan(0)
            ->and($warning->severity)->toBe(DiagnosticSeverity::Warning)
            ->and($deprecation->severity)->toBe(DiagnosticSeverity::Deprecation);
    }

    #[Test]
    public function diagnosticsMaskedByTheSuppressionOperatorAreNotRecorded(): void
    {
        $capture = new OutputCapture();
        $capture->start();

        @\trigger_error('suppressed', \E_USER_NOTICE);

        $captured = $capture->stop();

        Expect::that($captured->diagnostics)->because('diagnostics masked by the suppression operator are not recorded')->toBe([]);
    }

    #[Test]
    public function stopPreservesAnErrorHandlerInstalledDuringCapture(): void
    {
        $baselineHandler = $this->activeErrorHandler();
        $messages = [];
        $capture = new OutputCapture();
        $capture->start();

        \set_error_handler(
            static function (int $severity, string $message) use (&$messages): bool {
                $messages[] = [$severity, $message];

                return true;
            },
        );

        try {
            $capture->stop();
            \trigger_error('after capture', \E_USER_NOTICE);

            Expect::that($messages)
                ->because('stop preserves an error handler installed during capture')
                ->toBe([[\E_USER_NOTICE, 'after capture']]);
        } finally {
            $this->restoreErrorHandler($baselineHandler);
        }
    }

    #[Test]
    public function truncationKeepsTheHeadAndSetsTheFlag(): void
    {
        $capture = new OutputCapture(maxStdoutBytes: 8);
        $capture->start();

        echo '0123456789';
        echo 'more after the bound';

        $captured = $capture->stop();

        Expect::that($captured->stdout)->because('truncation keeps the head and sets the flag')->toBe('01234567')
            ->and($captured->stdoutTruncated)->toBeTrue();
    }

    #[Test]
    public function outputExactlyAtTheBoundIsNotFlaggedAsTruncated(): void
    {
        $capture = new OutputCapture(maxStdoutBytes: 4);
        $capture->start();

        echo 'full';

        $captured = $capture->stop();

        Expect::that($captured->stdout)->because('output exactly at the bound is not flagged as truncated')->toBe('full')
            ->and($captured->stdoutTruncated)->toBeFalse();
    }

    #[Test]
    public function diagnosticsBeyondTheBoundAreDroppedAndFlagged(): void
    {
        $capture = new OutputCapture(maxDiagnostics: 2);
        $capture->start();

        \trigger_error('one', \E_USER_NOTICE);
        \trigger_error('two', \E_USER_NOTICE);
        \trigger_error('three', \E_USER_NOTICE);

        $captured = $capture->stop();

        Expect::that($captured->diagnostics)->because('diagnostics beyond the bound are dropped and flagged')->toHaveCount(2)
            ->and($captured->diagnostics[0]->message)->toBe('one')
            ->and($captured->diagnosticsTruncated)->toBeTrue();
    }

    #[Test]
    public function binaryBytesInCapturedStdoutAreScrubbed(): void
    {
        $capture = new OutputCapture();
        $capture->start();

        echo "binary \xB1\x31 output";

        $captured = $capture->stop();

        Expect::that($captured->stdout)->because('binary bytes in captured stdout are scrubbed')->toMatch('//u')
            ->toContain('binary')
            ->toContain('1 output');
    }

    #[Test]
    public function stopInAFinallyBlockRestoresEverythingWhenUserCodeThrows(): void
    {
        $baseline = \ob_get_level();
        $capture = new OutputCapture();
        $thrown = null;

        $capture->start();

        try {
            echo 'before the throw';

            throw new \RuntimeException('boom');
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        } finally {
            $captured = $capture->stop();
        }

        Expect::that($thrown)->because('stop in a finally block restores everything when user code throws')->toBeInstanceOf(\RuntimeException::class)
            ->and($captured->stdout)->toBe('before the throw')
            ->and(\ob_get_level())->toBe($baseline);
    }

    #[Test]
    public function theCaptureIsReusableAcrossWindows(): void
    {
        $capture = new OutputCapture();

        $capture->start();
        echo 'first';
        $first = $capture->stop();

        $capture->start();
        echo 'second';
        \trigger_error('only in the second window', \E_USER_NOTICE);
        $second = $capture->stop();

        Expect::that($first->stdout)->because('the capture is reusable across windows')->toBe('first')
            ->and($first->diagnostics)->toBe([])
            ->and($second->stdout)->toBe('second')
            ->and($second->diagnostics)->toHaveCount(1);
    }

    #[Test]
    public function stoppingWithoutStartingThrows(): void
    {
        Expect::that(static fn(): mixed => new OutputCapture()->stop())->because('stop() without start() throws')
            ->toThrow(CaptureError::class, '/not active.*start\(\)/');
    }

    #[Test]
    public function startingTwiceThrows(): void
    {
        $capture = new OutputCapture();
        $capture->start();

        try {
            Expect::that(static fn() => $capture->start())
                ->toThrow(CaptureError::class, '/already active.*stop\(\)/');
        } finally {
            $capture->stop();
        }
    }

    #[Test]
    public function aNonPositiveStdoutBoundIsRejected(): void
    {
        Expect::that(static fn(): OutputCapture => new OutputCapture(maxStdoutBytes: 0))->because('a nonpositive stdout bound is rejected')
            ->toThrow(\InvalidArgumentException::class, '/at least 1 byte/');
    }

    #[Test]
    public function aNonPositiveDiagnosticsBoundIsRejected(): void
    {
        Expect::that(static fn(): OutputCapture => new OutputCapture(maxDiagnostics: 0))->because('a nonpositive diagnostics bound is rejected')
            ->toThrow(\InvalidArgumentException::class, '/at least 1 entry/');
    }

    private function activeErrorHandler(): ?callable
    {
        $probe = static fn(): bool => false;
        $active = \set_error_handler($probe);
        \restore_error_handler();

        return $active;
    }

    private function restoreErrorHandler(?callable $baseline): void
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            if ($this->activeErrorHandler() === $baseline) {
                return;
            }

            \restore_error_handler();
        }

        throw new \RuntimeException('Failed to restore the test error-handler stack.');
    }
}
