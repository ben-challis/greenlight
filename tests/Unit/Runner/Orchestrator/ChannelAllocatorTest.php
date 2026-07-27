<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\ChannelAllocator;

final readonly class ChannelAllocatorTest
{
    #[Test]
    public function allocatesTheLowestFreeChannelFirst(): void
    {
        $allocator = new ChannelAllocator(4);

        Expect::that($allocator->allocate())->toBe(1)
            ->and($allocator->allocate())->toBe(2)
            ->and($allocator->allocate())->toBe(3)
            ->and($allocator->allocate())->toBe(4);
    }

    #[Test]
    public function releasedChannelsAreReused(): void
    {
        $allocator = new ChannelAllocator(3);
        $allocator->allocate();
        $allocator->allocate();
        $allocator->allocate();

        $allocator->release(2);

        Expect::that($allocator->allocate())->toBe(2);
    }

    #[Test]
    public function neverHandsOutMoreThanTheBound(): void
    {
        $allocator = new ChannelAllocator(2);
        $allocator->allocate();
        $allocator->allocate();

        Expect::that(static function () use ($allocator): void {
            $allocator->allocate();
        })->toThrow(\LogicException::class, matching: '/channels are in use/');
    }

    #[Test]
    public function channelsStayWithinTheBoundAcrossChurn(): void
    {
        // Worker replacement and crash containment start workers many times.
        // The occupied set MUST remain within 1..bound.
        $allocator = new ChannelAllocator(2);
        $first = $allocator->allocate();
        $second = $allocator->allocate();

        for ($round = 0; $round < 10; ++$round) {
            $allocator->release($second);
            $second = $allocator->allocate();

            Expect::that($second)->toBeLessThan(3)
                ->toBeGreaterThan(0)
                ->not()->toBe($first);
        }
    }

    #[Test]
    public function releasingAnUnallocatedChannelFailsLoudly(): void
    {
        $allocator = new ChannelAllocator(2);
        $allocator->allocate();
        $allocator->release(1);

        Expect::that(static function () use ($allocator): void {
            $allocator->release(1);
        })->toThrow(\LogicException::class, matching: '/not allocated/');
    }
}
