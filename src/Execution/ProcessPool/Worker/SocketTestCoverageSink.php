<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Worker;

use Greenlight\Coverage\Attribution\TestCoverageSink;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\CoverageChunk;
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

    public function __construct(private readonly SocketChannel $channel)
    {
        $this->coverage = CoverageMap::empty();
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
        }
    }

    public function coverage(): CoverageMap
    {
        return $this->coverage;
    }
}
