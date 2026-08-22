<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\ChannelAllocator;
use Greenlight\Expect\Expect;

final readonly class ChannelAllocatorTest
{
    #[Test]
    #[DataSet('invalidBounds')]
    public function rejectsInvalidBounds(int $bound): void
    {
        Expect::that(static fn(): ChannelAllocator => new ChannelAllocator($bound))
            ->because('a channel allocator MUST have at least one channel')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'The channel bound must be at least 1.',
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBounds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    public function allocatesTheLowestFreeChannelFirst(): void
    {
        $allocator = new ChannelAllocator(4);

        Expect::that($allocator->allocate())->because('allocates the lowest free channel first')->toBe(1);
        Expect::that($allocator->allocate())->toBe(2);
        Expect::that($allocator->allocate())->toBe(3);
        Expect::that($allocator->allocate())->toBe(4);
    }

    #[Test]
    public function releasedChannelsAreReused(): void
    {
        $allocator = new ChannelAllocator(3);
        $allocator->allocate();
        $allocator->allocate();
        $allocator->allocate();

        $allocator->release(2);

        Expect::that($allocator->allocate())->because('released channels are reused')->toBe(2);
    }

    #[Test]
    public function neverHandsOutMoreThanTheBound(): void
    {
        $allocator = new ChannelAllocator(2);
        $allocator->allocate();
        $allocator->allocate();

        Expect::that(static function () use ($allocator): void {
            $allocator->allocate();
        })->because('does not allocate more channels than the limit')->toThrow(\LogicException::class, matching: '/channels are in use/');
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
        })->because('releasing an unallocated channel causes an error')->toThrow(\LogicException::class, matching: '/not allocated/');
    }
}
