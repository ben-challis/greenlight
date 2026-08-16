<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\WorkerCount;
use Greenlight\Expect\Expect;

final readonly class WorkerCountMinimumTest
{
    #[Test]
    public function oneWorkerIsAValidFixedCount(): void
    {
        $workers = WorkerCount::exactly(1);

        Expect::that($workers->fixed)
            ->because('a fixed runner MUST support the minimum worker count')
            ->toBe(1);
        Expect::that($workers->isAuto())
            ->toBeFalse();
        Expect::that($workers->describe())
            ->toBe('1');
    }
}
