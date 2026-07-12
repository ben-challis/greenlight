<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

/**
 * Hands out worker channel numbers from a fixed pool of 1 to the bound.
 *
 * allocate() returns the lowest free number, so a run with N workers only
 * ever occupies channels 1 to N no matter how many workers are spawned over
 * its lifetime. release() returns a number to the pool when a worker's
 * handle finishes, letting the replacement worker reuse it.
 *
 * Exhaustion or a double release is a bookkeeping bug in the caller and
 * fails loudly.
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
     * @param positive-int $bound
     */
    public function __construct(private readonly int $bound) {}

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
            'All %d worker channels are in use; a channel was not released when its worker finished.',
            $this->bound,
        ));
    }

    public function release(int $channel): void
    {
        if (!isset($this->inUse[$channel])) {
            throw new \LogicException(\sprintf(
                'Channel %d is not allocated; releasing it twice hides a lifecycle bug.',
                $channel,
            ));
        }

        unset($this->inUse[$channel]);
    }
}
