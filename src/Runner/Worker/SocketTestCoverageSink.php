<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Runner\Protocol\Messages\CoverageChunk;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\TestCoverageSink;

/**
 * Streams bounded per-test coverage chunks over the worker socket.
 *
 * @internal
 */
final class SocketTestCoverageSink implements TestCoverageSink
{
    private CoverageMap $coverage;

    public function __construct(private readonly SocketChannel $channel)
    {
        $this->coverage = CoverageMap::empty();
    }

    #[\Override]
    public function record(TestId $id, CoverageMap $coverage): void
    {
        $this->coverage = $this->coverage->merge($coverage);

        foreach ($coverage->files() as $file) {
            foreach (\array_chunk($file->coveredLines, 50_000) as $lines) {
                $this->channel->send(new CoverageChunk($id, $file->file, $lines));
            }
        }
    }

    public function coverage(): CoverageMap
    {
        return $this->coverage;
    }
}
