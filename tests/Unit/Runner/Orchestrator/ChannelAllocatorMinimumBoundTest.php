<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\ChannelAllocator;

final readonly class ChannelAllocatorMinimumBoundTest
{
    #[Test]
    public function oneChannelIsAValidAndEnforcedBound(): void
    {
        $allocator = new ChannelAllocator(1);

        Expect::that($allocator->allocate())
            ->because('a single worker channel MUST be a valid allocation bound')
            ->toBe(1)
            ->and(static fn(): int => $allocator->allocate())
            ->toThrow(
                \LogicException::class,
                message: 'All 1 worker channels are in use. A worker finished without releasing its channel.',
            );
    }
}
