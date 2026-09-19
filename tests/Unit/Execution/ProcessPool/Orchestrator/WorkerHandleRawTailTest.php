<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\MemoryStream;

final readonly class WorkerHandleRawTailTest
{
    #[Test]
    #[DataSet('tails')]
    public function rawCollectionPreservesTheCompleteNormalizedTail(string $input, string $expected): void
    {
        $process = MemoryStream::open();
        $stdout = MemoryStream::open($input);
        $stderr = MemoryStream::open();

        try {
            $handle = new WorkerHandle('worker-1', 1, $process, $stdout, $stderr);
            $handle->drainPipes();

            Expect::that($handle->diagnostics)->toBe($expected);
            $handle->drainPipes();
            Expect::that($handle->diagnostics)->toBe($expected);
        } finally {
            MemoryStream::close($process, $stdout, $stderr);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function tails(): iterable
    {
        yield 'character across the final byte boundary' => [
            \str_repeat('x', 100_000) . "\u{1F600}" . \str_repeat('y', 65_533),
            \str_repeat('y', 65_533),
        ];
        yield 'invalid four-byte sequences contract to replacements' => [
            \str_repeat("\xF4\x90\x80\x80", 50_000),
            \str_repeat("\u{FFFD}", 21_845),
        ];
        yield 'contracted bytes precede a long ASCII suffix' => [
            \str_repeat('x', 100_000) . \str_repeat("\xF4\x90\x80\x80", 5_000) . \str_repeat('y', 60_000),
            \str_repeat("\u{FFFD}", 1_845) . \str_repeat('y', 60_000),
        ];
    }

    #[Test]
    public function bothLargeStreamsKeepTheirFinalTailInStreamOrder(): void
    {
        $process = MemoryStream::open();
        $stdout = MemoryStream::open(\str_repeat('x', 1024 * 1024) . 'output end');
        $stderr = MemoryStream::open(\str_repeat('y', 1024 * 1024) . "error end\xE2");

        try {
            $handle = new WorkerHandle('worker-1', 1, $process, $stdout, $stderr);
            $handle->drainPipes();

            $suffix = "error end\u{FFFD}";
            Expect::that($handle->diagnostics)->toBe(\str_repeat('y', 65_536 - \strlen($suffix)) . $suffix);
        } finally {
            MemoryStream::close($process, $stdout, $stderr);
        }
    }
}
