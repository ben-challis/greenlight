<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final class SchedulingUnitTest
{
    #[Test]
    public function itCannotBeEmpty(): void
    {
        Expect::that(static fn(): SchedulingUnit => new SchedulingUnit(new ExecutionPlan([]), false))->because('a scheduling unit cannot be empty')
            ->toThrow(\InvalidArgumentException::class, '/cannot be empty/');
    }
}
