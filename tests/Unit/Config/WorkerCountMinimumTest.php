<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\WorkerCount;

use function Greenlight\expect;

final readonly class WorkerCountMinimumTest
{
    #[Test]
    public function oneWorkerIsAValidFixedCount(): void
    {
        $workers = WorkerCount::exactly(1);

        expect($workers->fixed)
            ->because('a fixed runner MUST support the minimum worker count')
            ->toBe(1);
        expect($workers->isAuto())
            ->toBeFalse();
        expect($workers->describe())
            ->toBe('1');
    }
}
