<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Expect\Expect;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\OutputCaptureCapability;
use Greenlight\Tests\Support\MemoryStream;

final readonly class WorkerHandleDiagnosticStreamsTest
{
    #[Test]
    public function pipeDrainKeepsStdoutBeforeStderr(): void
    {
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            MemoryStream::open("standard output\n"),
            MemoryStream::open("standard error\n"),
        );

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('worker diagnostics MUST contain standard output followed by standard error')
            ->toBe("standard output\nstandard error\n");
    }

    #[Test]
    public function pipeDrainKeepsTheBoundedTailOfAvailableOutput(): void
    {
        $tail = \str_repeat('t', 65_536);
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            MemoryStream::open(\str_repeat('p', 8_192) . $tail),
            MemoryStream::open(),
        );

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('one pipe drain MUST retain the bounded tail of all available output')
            ->toBe($tail);
    }

    #[Test]
    public function activeCaptureKeepsDescriptorStreamsSeparateAndScrubsBinaryBytes(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $handle = new WorkerHandle('worker-1', 1, MemoryStream::open(), $stdout, $stderr);
        $handle->startOutputCapture(true);
        \fwrite($stdout, "stdout\xFF");
        \fwrite($stderr, "stderr\xFF");
        \rewind($stdout);
        \rewind($stderr);

        $captured = $handle->finishOutputCapture();

        Expect::that($captured?->stdout)->toBe("stdout\u{FFFD}");
        Expect::that($captured?->stderr)->toBe("stderr\u{FFFD}");
        Expect::that($captured?->capability)->toBe(OutputCaptureCapability::ProcessDescriptors);
        Expect::that($handle->diagnostics)->toBe('');
    }

    #[Test]
    public function descriptorStreamsHaveIndependentStrictByteBounds(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $handle = new WorkerHandle('worker-1', 1, MemoryStream::open(), $stdout, $stderr);
        $handle->startOutputCapture(true);
        \fwrite($stdout, \str_repeat('o', 1_048_577));
        \fwrite($stderr, \str_repeat('e', 1_048_576));
        \rewind($stdout);
        \rewind($stderr);

        $captured = $handle->finishOutputCapture();

        if (!$captured instanceof CapturedOutput) {
            throw new \RuntimeException('The active descriptor capture returned no output.');
        }

        Expect::that(\strlen($captured->stdout))->toBe(1_048_576);
        Expect::that(\strlen($captured->stderr))->toBe(1_048_576);
        Expect::that($captured->stdoutTruncated)->toBeTrue();
        Expect::that($captured->stderrTruncated)->toBeFalse();
    }

    #[Test]
    public function descriptorCaptureReportsBytesThatArriveAfterTheBoundIsFull(): void
    {
        $stdout = MemoryStream::open();
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            $stdout,
            MemoryStream::open(),
        );
        $handle->startOutputCapture(true);
        \fwrite($stdout, \str_repeat('o', 1_048_576));
        \rewind($stdout);
        $handle->drainPipes();
        \fwrite($stdout, 'later');
        \fseek($stdout, 1_048_576);
        $handle->drainPipes();

        $captured = $handle->finishOutputCapture();

        Expect::that($captured?->stdout)->toBe(\str_repeat('o', 1_048_576));
        Expect::that($captured?->stdoutTruncated)
            ->because('bytes that arrive after the descriptor bound MUST set the truncation flag')
            ->toBeTrue();
    }

    #[Test]
    public function disabledCaptureDiscardsAttemptBytes(): void
    {
        $stdout = MemoryStream::open();
        $handle = new WorkerHandle('worker-1', 1, MemoryStream::open(), $stdout, MemoryStream::open());
        $handle->startOutputCapture(false);
        \fwrite($stdout, 'discarded');
        \rewind($stdout);

        Expect::that($handle->finishOutputCapture())->toBeNull();
        Expect::that($handle->diagnostics)->toBe('');
    }

}
