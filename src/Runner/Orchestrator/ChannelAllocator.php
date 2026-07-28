<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

/**
 * Allocates the lowest free channel and reuses a released channel. The allocator
 * throws an error if no channel is free or a release is invalid.
 *
 * @internal
 */
final class ChannelAllocator
{
    /**
     * @var array<int, true>
     */
    private array $inUse = [];

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(private readonly int $bound)
    {
        if ($bound < 1) {
            throw new \InvalidArgumentException('The channel bound must be at least 1.');
        }
    }

    /**
     * @return positive-int
     */
    public function allocate(): int
    {
        for ($channel = 1; $channel <= $this->bound; ++$channel) {
            if (!isset($this->inUse[$channel])) {
                $this->inUse[$channel] = true;

                return $channel;
            }
        }

        throw new \LogicException(\sprintf(
            'All %d worker channels are in use. A worker finished without releasing its channel.',
            $this->bound,
        ));
    }

    public function release(int $channel): void
    {
        if (!isset($this->inUse[$channel])) {
            throw new \LogicException(\sprintf(
                'Channel %d is not allocated. A second release indicates a lifecycle error.',
                $channel,
            ));
        }

        unset($this->inUse[$channel]);
    }
}
