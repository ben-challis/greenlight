<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Capture\CaptureError;
use Greenlight\Capture\OutputCapture;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final readonly class OutputCaptureTest
{
    public function __construct(private Cleanup $cleanup) {}

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

        Expect::that($captured->stdout)->because('echo inside the window is captured and does not reach the outer stream')->toBe('hello from the test');
        Expect::that($captured->stdoutTruncated)->toBeFalse();
        Expect::that($leaked)->toBe('');
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

        Expect::that($inner)->because('user code nesting its own output buffers keeps working')->toBe('inner');
        Expect::that($captured->stdout)->toBe('ab');
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

        Expect::that($captured->stdout)->because('a user buffer left open is flushed into the capture')->toBe('head leftover');
        Expect::that(\ob_get_level())->toBe($baseline);
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

        Expect::that($captured->diagnostics)->because('notices warnings and deprecations are recorded with file and line')->toHaveCount(3);
        Expect::that($captured->stdout)->toBe('');

        [$notice, $warning, $deprecation] = $captured->diagnostics;

        Expect::that($notice->severity)->because('notices warnings and deprecations are recorded with file and line')->toBe(DiagnosticSeverity::Notice);
        Expect::that($notice->message)->toBe('a notice');
        Expect::that($notice->file)->toBe(__FILE__);
        Expect::that($notice->line)->toBeGreaterThan(0);
        Expect::that($warning->severity)->toBe(DiagnosticSeverity::Warning);
        Expect::that($deprecation->severity)->toBe(DiagnosticSeverity::Deprecation);
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
    public function unsupportedEngineLevelsRemainAvailableToPhp(): void
    {
        $capture = new OutputCapture();
        $capture->start();

        try {
            $handler = $this->activeErrorHandler();

            if (!$handler instanceof \Closure) {
                throw new \RuntimeException('Output capture did not install its error handler.');
            }

            $handled = $handler(\E_ERROR, 'engine failure', __FILE__, __LINE__);
        } finally {
            $captured = $capture->stop();
        }

        Expect::that($handled)
            ->because('PHP MUST handle diagnostic levels that Greenlight does not capture')
            ->toBeFalse();
        Expect::that($captured->diagnostics)
            ->because('unsupported diagnostic levels MUST NOT become test diagnostics')
            ->toBe([]);
    }

    #[Test]
    public function stopPreservesErrorHandlersInstalledDuringCapture(): void
    {
        $baselineHandler = $this->activeErrorHandler();
        $lowerMessages = [];
        $upperMessages = [];
        $capture = new OutputCapture();
        $capture->start();

        \set_error_handler(
            static function (int $severity, string $message) use (&$lowerMessages): bool {
                $lowerMessages[] = [$severity, $message];

                return true;
            },
        );
        \set_error_handler(
            static function (int $severity, string $message) use (&$upperMessages): bool {
                $upperMessages[] = [$severity, $message];

                return true;
            },
        );

        try {
            $capture->stop();
            \trigger_error('upper handler', \E_USER_NOTICE);
            \restore_error_handler();
            \trigger_error('lower handler', \E_USER_NOTICE);
            \restore_error_handler();
            $restored = $this->activeErrorHandler();

            Expect::that($upperMessages)
                ->because('stop preserves the newest error handler installed during capture')
                ->toBe([[\E_USER_NOTICE, 'upper handler']]);
            Expect::that($lowerMessages)
                ->because('stop preserves the complete user error-handler stack')
                ->toBe([[\E_USER_NOTICE, 'lower handler']]);
            Expect::that($restored)
                ->because('restoring the user handlers MUST reveal the pre-capture handler')
                ->toBe($baselineHandler);
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

        Expect::that($captured->stdout)->because('truncation keeps the head and sets the flag')->toBe('01234567');
        Expect::that($captured->stdoutTruncated)->toBeTrue();
    }

    #[Test]
    public function outputExactlyAtTheBoundIsNotFlaggedAsTruncated(): void
    {
        $capture = new OutputCapture(maxStdoutBytes: 4);
        $capture->start();

        echo 'full';

        $captured = $capture->stop();

        Expect::that($captured->stdout)->because('output exactly at the bound is not flagged as truncated')->toBe('full');
        Expect::that($captured->stdoutTruncated)->toBeFalse();
    }

    #[Test]
    public function truncationDoesNotKeepAPartialUnicodeCharacter(): void
    {
        $capture = new OutputCapture(maxStdoutBytes: 4);
        $capture->start();

        echo 'ab€cd';

        $captured = $capture->stop();

        Expect::that($captured->stdout)
            ->because('captured output MUST contain only complete Unicode characters within its byte limit')
            ->toBe('ab');
        Expect::that(\strlen($captured->stdout))
            ->because('captured output MUST stay within its byte limit')
            ->toBeLessThanOrEqual(4);
        Expect::that($captured->stdoutTruncated)
            ->because('captured output beyond the byte limit MUST be marked as truncated')
            ->toBeTrue();
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

        Expect::that($captured->diagnostics)->because('diagnostics beyond the bound are dropped and flagged')->toHaveCount(2);
        Expect::that($captured->diagnostics[0]->message)->toBe('one');
        Expect::that($captured->diagnosticsTruncated)->toBeTrue();
    }

    #[Test]
    public function diagnosticsExactlyAtTheBoundAreNotFlaggedAsTruncated(): void
    {
        $capture = new OutputCapture(maxDiagnostics: 2);
        $capture->start();

        \trigger_error('one', \E_USER_NOTICE);
        \trigger_error('two', \E_USER_NOTICE);

        $captured = $capture->stop();

        Expect::that($captured->diagnostics)
            ->because('diagnostics at the retention bound MUST remain complete')
            ->toHaveCount(2);
        Expect::that($captured->diagnosticsTruncated)
            ->toBeFalse();
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
        $failure = new \RuntimeException('boom');
        $captured = null;

        $capture->start();

        Expect::that(static function () use ($capture, $failure, &$captured): never {
            try {
                echo 'before the throw';

                throw $failure;
            } finally {
                $captured = $capture->stop();
            }
        })
            ->because('stop in a finally block restores everything when user code throws')
            ->toThrow($failure);

        Expect::that($captured?->stdout)->toBe('before the throw');
        Expect::that(\ob_get_level())->toBe($baseline);
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

        Expect::that($first->stdout)->because('the capture is reusable across windows')->toBe('first');
        Expect::that($first->diagnostics)->toBe([]);
        Expect::that($second->stdout)->toBe('second');
        Expect::that($second->diagnostics)->toHaveCount(1);
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
    public function aNonRemovableNestedBufferDoesNotHangStop(): void
    {
        $root = \dirname(__DIR__, 3);
        $process = Subprocess::start($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP_WRAP'
            require $argv[1];

            $capture = new Greenlight\Capture\OutputCapture();
            $capture->start();
            ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE);

            try {
                $capture->stop();
            } catch (Greenlight\Capture\CaptureError $error) {
                fwrite(STDERR, $error->getMessage());
                exit(23);
            }
            PHP_WRAP,
            $root . '/vendor/autoload.php',
        ]);
        $this->cleanup->defer($process->terminate(...));

        $result = $process->wait(2.0);

        Expect::that($result->exitCode)
            ->because('a blocked nested output buffer MUST fail without hanging the worker')
            ->toBe(23);
        Expect::that($result->stderr)
            ->toBe('Output capture cannot stop because a nested output buffer cannot be removed.');
    }

    #[Test]
    #[DataSet('nonPositiveBounds')]
    public function nonPositiveBoundsAreRejected(
        int $maxStdoutBytes,
        int $maxDiagnostics,
        string $message,
    ): void {
        Expect::that(static fn(): OutputCapture => new OutputCapture($maxStdoutBytes, $maxDiagnostics))
            ->because('output capture bounds MUST be positive')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{int, int, non-empty-string}>
     */
    public static function nonPositiveBounds(): iterable
    {
        yield 'zero stdout bound' => [0, 1, 'Stdout bound must be at least 1 byte, got 0.'];
        yield 'negative stdout bound' => [-1, 1, 'Stdout bound must be at least 1 byte, got -1.'];
        yield 'zero diagnostics bound' => [1, 0, 'Diagnostics bound must be at least 1 entry, got 0.'];
        yield 'negative diagnostics bound' => [1, -1, 'Diagnostics bound must be at least 1 entry, got -1.'];
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
