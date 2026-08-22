<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\ChannelAllocator;
use Greenlight\Expect\Expect;

final readonly class ChannelAllocatorMinimumBoundTest
{
    #[Test]
    public function oneChannelIsAValidAndEnforcedBound(): void
    {
        $allocator = new ChannelAllocator(1);

        Expect::that($allocator->allocate())
            ->because('a single worker channel MUST be a valid allocation bound')
            ->toBe(1);
        Expect::that(static fn(): int => $allocator->allocate())
            ->toThrow(
                \LogicException::class,
                message: 'All 1 worker channels are in use. A worker finished without releasing its channel.',
            );
    }
}
