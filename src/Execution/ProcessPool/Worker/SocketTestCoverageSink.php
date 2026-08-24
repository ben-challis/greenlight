<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Worker;

use Greenlight\Coverage\Attribution\TestCoverageSink;
use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\PathCoverage;
use Greenlight\Execution\ProcessPool\Protocol\Messages\BranchCoverageChunk;
use Greenlight\Execution\ProcessPool\Protocol\Messages\CoverageChunk;
use Greenlight\Execution\ProcessPool\Protocol\Messages\PathCoverageChunk;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Test\TestId;

/**
 * Streams bounded per-test coverage records to the orchestrator.
 *
 * @internal
 */
final class SocketTestCoverageSink implements TestCoverageSink
{
    private const int LINES_PER_MESSAGE = 50_000;

    private CoverageMap $coverage;

    public function __construct(private readonly SocketChannel $channel, bool $branchCoverage = false)
    {
        $this->coverage = CoverageMap::empty($branchCoverage);
    }

    /** @throws ProtocolError */
    #[\Override]
    public function record(TestId $id, CoverageMap $coverage): void
    {
        $this->coverage = $this->coverage->merge($coverage);

        foreach ($coverage->files() as $file) {
            foreach (\array_chunk($file->coveredLines, self::LINES_PER_MESSAGE) as $lines) {
                $this->channel->send(new CoverageChunk($id, $file->file, $lines));
            }

            foreach ($file->functions as $function) {
                $branches = \array_map(
                    static fn(BranchCoverage $branch): int => $branch->id,
                    \array_values(\array_filter($function->branches, static fn(BranchCoverage $branch): bool => $branch->covered)),
                );

                foreach (\array_chunk($branches, self::LINES_PER_MESSAGE) as $chunk) {
                    $this->channel->send(new BranchCoverageChunk($id, $file->file, $function->name, $chunk));
                }

                $paths = \array_map(
                    static fn(PathCoverage $path): array => $path->branches,
                    \array_values(\array_filter($function->paths, static fn(PathCoverage $path): bool => $path->covered)),
                );

                foreach (\array_chunk($paths, self::LINES_PER_MESSAGE) as $chunk) {
                    $this->channel->send(new PathCoverageChunk($id, $file->file, $function->name, $chunk));
                }
            }
        }
    }

    public function coverage(): CoverageMap
    {
        return $this->coverage;
    }
}
