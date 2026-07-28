<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
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
    public function selectedEntriesPreserveTheirPlanOrder(): void
    {
        $plan = $this->plan(15);
        $shard = PlanShard::select($plan, index: 3, count: 4);
        $actual = \array_map(
            static fn(PlanEntry $entry): string => (string) $entry->id,
            $shard->entries,
        );
        $expected = [];

        foreach ($plan->entries as $entry) {
            if (\in_array((string) $entry->id, $actual, true)) {
                $expected[] = (string) $entry->id;
            }
        }

        Expect::that($actual)
            ->because('sharding MUST preserve class, method, and data-set order')
            ->toBe($expected);
    }

    #[Test]
    public function oneShardIsTheWholePlan(): void
    {
        $plan = $this->plan(5);

        Expect::that(PlanShard::select($plan, 1, 1))->because('one shard is the whole plan')->toBe($plan);
    }

    #[Test]
    #[DataSet('invalidShards')]
    public function invalidShardsAreRejected(int $index, int $count, string $message): void
    {
        $plan = $this->plan(1);

        Expect::that(static function () use ($plan, $index, $count): void {
            PlanShard::select($plan, $index, $count);
        })
            ->because('invalid shard coordinates MUST NOT select an execution plan')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function invalidShards(): iterable
    {
        yield 'zero count' => [1, 0, 'The shard count must be at least 1.'];
        yield 'negative count' => [1, -1, 'The shard count must be at least 1.'];
        yield 'zero index' => [0, 2, 'The shard index must be between 1 and 2.'];
        yield 'negative index' => [-1, 2, 'The shard index must be between 1 and 2.'];
        yield 'index above count' => [3, 2, 'The shard index must be between 1 and 2.'];
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
