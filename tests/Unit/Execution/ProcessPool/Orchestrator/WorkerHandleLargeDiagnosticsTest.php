<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\ConnectedStreamPair;
use Greenlight\Tests\Support\MemoryStream;

final readonly class WorkerHandleLargeDiagnosticsTest
{
    #[Test]
    public function malformedBytesPreserveTheNextSplitUnicodeCharacter(): void
    {
        [$reader, $writer] = ConnectedStreamPair::open();
        $process = MemoryStream::open();
        $stderr = MemoryStream::open();

        try {
            $handle = new WorkerHandle('worker-1', 1, $process, $reader, $stderr);
            \fwrite($writer, "\xFF\xE2");
            $handle->drainPipes();
            \fwrite($writer, "\x82\xAC");
            $handle->drainPipes();

            Expect::that($handle->diagnostics)
                ->because('malformed bytes MUST NOT replace a later complete Unicode character')
                ->toBe("\u{FFFD}\u{20AC}");
        } finally {
            MemoryStream::close($reader, $writer, $process, $stderr);
        }
    }

    #[Test]
    public function aLargeReadPreservesUnicodeAndTheFinalDiagnosticTail(): void
    {
        $tail = "\u{20AC}" . \str_repeat('y', 65_530) . "\u{FFFD}";
        $process = MemoryStream::open();
        $stdout = MemoryStream::open(\str_repeat('x', 131_070) . "\xFF\u{20AC}" . \str_repeat('y', 65_530) . "\xE2");
        $stderr = MemoryStream::open();

        try {
            $handle = new WorkerHandle('worker-1', 1, $process, $stdout, $stderr);
            $handle->drainPipes();

            Expect::that($handle->diagnostics)
                ->because('a large pipe read MUST preserve the complete final Unicode tail')
                ->toBe($tail);

            $handle->drainPipes();

            Expect::that($handle->diagnostics)
                ->because('another drain at EOF MUST NOT duplicate diagnostic bytes')
                ->toBe($tail);
        } finally {
            MemoryStream::close($process, $stdout, $stderr);
        }
    }
}
