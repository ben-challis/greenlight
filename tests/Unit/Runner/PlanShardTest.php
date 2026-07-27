<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\PlanShard;

final class PlanShardTest
{
    #[Test]
    #[DataRow([2], label: 'two shards')]
    #[DataRow([3], label: 'three shards')]
    #[DataRow([7], label: 'more shards than some classes get')]
    public function shardsPartitionThePlan(int $count): void
    {
        $plan = $this->plan(40);
        $seen = [];
        $total = 0;

        for ($index = 1; $index <= $count; ++$index) {
            $shard = PlanShard::select($plan, $index, $count);

            foreach ($shard->entries as $entry) {
                $id = (string) $entry->id;
                // The shards do not overlap. A test ID MUST NOT occur in two
                // shards.
                Expect::that($seen)->not()->toHaveKey($id);
                $seen[$id] = true;
            }

            $total += \count($shard->entries);
        }

        // The union of the shards is the complete execution plan.
        Expect::that($total)->because('shards partition the plan')->toBe(\count($plan->entries));
    }

    #[Test]
    public function classesNeverSplitAcrossShards(): void
    {
        $plan = $this->plan(15);

        for ($index = 1; $index <= 4; ++$index) {
            $classes = [];

            foreach (PlanShard::select($plan, $index, 4)->entries as $entry) {
                $classes[$entry->id->class] = true;
            }

            foreach (\array_keys($classes) as $class) {
                $expected = \crc32($class) % 4 === $index - 1;
                Expect::that($expected)->toBeTrue();
            }
        }
    }

    #[Test]
    public function oneShardIsTheWholePlan(): void
    {
        $plan = $this->plan(5);

        Expect::that(PlanShard::select($plan, 1, 1))->because('one shard is the whole plan')->toBe($plan);
    }

    private function plan(int $classes): ExecutionPlan
    {
        $entries = [];

        for ($i = 0; $i < $classes; ++$i) {
            $class = \sprintf('Acme\Gen%03dTest', $i);

            foreach (['one', 'two'] as $method) {
                $entries[] = new PlanEntry(new TestId($class, $method), new TestMetadata($class, $method));
            }
        }

        return new ExecutionPlan($entries);
    }
}
