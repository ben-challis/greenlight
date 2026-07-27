<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\WorkerCount;
use Greenlight\Expect\Expect;

final class WorkerCountTest
{
    #[Test]
    public function autoAndFixedCountsExposeTheirStateAndDescription(): void
    {
        $auto = WorkerCount::auto();
        $fixed = WorkerCount::exactly(3);

        Expect::that($auto->fixed)
            ->because('the automatic worker count has no fixed value')
            ->toBeNull();
        Expect::that($auto->isAuto())
            ->because('the automatic worker count identifies its state')
            ->toBeTrue();
        Expect::that($auto->describe())
            ->because('the automatic worker count has a stable description')
            ->toBe('auto');
        Expect::that($fixed->fixed)
            ->because('the fixed worker count exposes its value')
            ->toBe(3);
        Expect::that($fixed->isAuto())
            ->because('the fixed worker count identifies its state')
            ->toBeFalse();
        Expect::that($fixed->describe())
            ->because('the fixed worker count renders its value')
            ->toBe('3');
    }

    #[Test]
    #[DataSet('nonpositiveCounts')]
    public function nonpositiveCountsGiveExactGuidance(int $count, string $message): void
    {
        Expect::that(static fn(): WorkerCount => WorkerCount::exactly($count))
            ->because('a worker count must be positive')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{int, non-empty-string}>
     */
    public static function nonpositiveCounts(): iterable
    {
        yield 'zero' => [0, 'Worker count must be at least 1, got 0.'];
        yield 'negative' => [-3, 'Worker count must be at least 1, got -3.'];
    }
}
