<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\WorkerHandle;
use Greenlight\Tests\Support\ConnectedStreamPair;
use Greenlight\Tests\Support\MemoryStream;
use Greenlight\Tests\Support\PlanEntryFixture;

final class WorkerHandleTest
{
    #[Test]
    public function lifecycleTimestampsUseTheMonotonicSystemClock(): void
    {
        $before = \hrtime(true) / 1_000_000_000;
        $handle = $this->handle();
        $after = \hrtime(true) / 1_000_000_000;

        Expect::that($handle->spawnedAt)
            ->because('worker lifecycle deadlines MUST use the monotonic system clock')
            ->toBeGreaterThanOrEqual($before)
            ->toBeLessThanOrEqual($after);
        Expect::that($handle->lastProgressAt)
            ->because('a new worker MUST start its progress deadline at its spawn time')
            ->toBe($handle->spawnedAt);
    }

    #[Test]
    public function aClosedProcessHandleIsNotRunning(): void
    {
        $process = MemoryStream::open();
        \fclose($process);
        $handle = new WorkerHandle(
            'worker-1',
            1,
            $process,
            MemoryStream::open(),
            MemoryStream::open(),
        );

        Expect::that($handle->isRunning())
            ->because('a closed worker process handle cannot be running')
            ->toBeFalse();
    }

    #[Test]
    public function unfinishedReturnsOnlyEntriesEligibleForCrashReassignment(): void
    {
        $handle = $this->handle();

        Expect::that($handle->unfinished())
            ->because('a worker without an assignment has no unfinished tests')
            ->toBe([]);

        $finished = PlanEntryFixture::create('Example\WorkerTest', 'finished');
        $inFlight = PlanEntryFixture::create('Example\WorkerTest', 'inFlight');
        $remaining = PlanEntryFixture::create('Example\WorkerTest', 'remaining');
        $handle->assigned = new ExecutionPlan([$finished, $inFlight, $remaining]);
        $handle->finished[(string) $finished->id] = true;
        $handle->inFlight = $inFlight->id;

        Expect::that($handle->unfinished())
            ->because('crash reassignment excludes finished and active tests')
            ->toBe([$remaining->id]);
    }

    #[Test]
    public function drainedDiagnosticsKeepACompleteUnicodeTailWithinTheByteLimit(): void
    {
        $stdout = MemoryStream::open();
        \fwrite($stdout, 'z');
        \rewind($stdout);

        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            $stdout,
            MemoryStream::open(),
        );
        $handle->diagnostics = 'xx€' . \str_repeat('y', 65_533);

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('drained diagnostics MUST contain only complete Unicode characters within the byte limit')
            ->toBe(\str_repeat('y', 65_533) . 'z');
        Expect::that(\strlen($handle->diagnostics))
            ->because('drained diagnostics MUST stay within the byte limit')
            ->toBeLessThanOrEqual(65_536);
    }

    #[Test]
    #[DataSet('splitUnicodeSequences')]
    public function diagnosticsPreserveAUnicodeCharacterSplitAcrossPipeReads(
        string $lead,
        string $remainder,
        string $expected,
    ): void {
        [$reader, $writer] = ConnectedStreamPair::open();
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            $reader,
            MemoryStream::open(),
        );

        \fwrite($writer, $lead);
        $handle->drainPipes();
        \fwrite($writer, $remainder);
        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('pipe reads MUST combine the bytes of one Unicode character')
            ->toBe($expected);
    }

    #[Test]
    #[DataSet('malformedUtf8Prefixes')]
    public function malformedTrailingBytesAreScrubbedWithoutWaitingForAnotherPipeRead(string $malformed): void
    {
        [$reader, $writer] = ConnectedStreamPair::open();
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            $reader,
            MemoryStream::open(),
        );

        \fwrite($writer, $malformed);
        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('malformed trailing bytes MUST be scrubbed during the current pipe read')
            ->toBe("\u{FFFD}");
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function splitUnicodeSequences(): iterable
    {
        yield 'two-byte minimum lead' => ["\xC2", "\xA2", '¢'];
        yield 'three-byte minimum lead' => ["\xE0", "\xA0\x80", "\u{0800}"];
        yield 'four-byte minimum lead' => ["\xF0", "\x90\x80\x80", "\u{10000}"];
        yield 'four-byte maximum lead' => ["\xF4", "\x8F\xBF\xBF", "\u{10FFFF}"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedUtf8Prefixes(): iterable
    {
        yield 'invalid lead' => ["\xFF"];
        yield 'overlong three-byte prefix' => ["\xE0\x80"];
        yield 'surrogate prefix' => ["\xED\xA0"];
        yield 'overlong four-byte prefix' => ["\xF0\x80"];
        yield 'out-of-range prefix' => ["\xF4\x90"];
    }

    private function handle(): WorkerHandle
    {
        return new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            MemoryStream::open(),
            MemoryStream::open(),
        );
    }
}
