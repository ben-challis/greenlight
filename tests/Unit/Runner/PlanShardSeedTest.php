<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\PlanShard;

final class PlanShardSeedTest
{
    #[Test]
    public function selectingAShardPreservesThePlanSeed(): void
    {
        $plan = new ExecutionPlan([
            new PlanEntry(
                new TestId('Acme\\PaymentTest', 'charges'),
                new TestMetadata('Acme\\PaymentTest', 'charges'),
            ),
        ], seed: 86420);

        Expect::that(PlanShard::select($plan, index: 1, count: 2)->seed)
            ->because('each shard MUST preserve the seed that defines its plan order')
            ->toBe(86420);
    }
}
