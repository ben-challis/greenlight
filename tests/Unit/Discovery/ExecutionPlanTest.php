<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class ExecutionPlanTest
{
    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private static function entry(string $class, string $method, ?string $dataSetKey = null): PlanEntry
    {
        return new PlanEntry(new TestId($class, $method, $dataSetKey), new TestMetadata($class, $method));
    }

    #[Test]
    public function exposesEntriesClassesAndCounts(): void
    {
        $plan = new ExecutionPlan([
            self::entry('App\FooTest', 'a'),
            self::entry('App\FooTest', 'b', 'first'),
            self::entry('App\BarTest', 'c'),
        ], 7);

        Expect::that($plan)->toHaveCount(3);
        Expect::that($plan->classes())->toBe(['App\FooTest', 'App\BarTest']);
        Expect::that($plan->seed)->toBe(7);

        $byClass = $plan->entriesByClass();

        Expect::that($byClass['App\FooTest'])->toHaveCount(2);
        Expect::that($byClass['App\BarTest'])->toHaveCount(1);
    }

    #[Test]
    public function rejectsEntriesNotGroupedByClass(): void
    {
        Expect::that(
            static fn(): ExecutionPlan => new ExecutionPlan([
                self::entry('App\FooTest', 'a'),
                self::entry('App\BarTest', 'c'),
                self::entry('App\FooTest', 'b'),
            ]),
        )->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function rejectsEntryWhoseIdAndMetadataDisagree(): void
    {
        Expect::that(
            static fn(): PlanEntry => new PlanEntry(
                new TestId('App\FooTest', 'a'),
                new TestMetadata('App\FooTest', 'b'),
            ),
        )->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function survivesTheWire(): void
    {
        $plan = new ExecutionPlan([
            self::entry('App\FooTest', 'a'),
            self::entry('App\FooTest', 'b', 'first case'),
        ], 42);

        $restored = ExecutionPlan::fromWire(JsonWire::roundTrip($plan->toWire()));

        Expect::that(\json_encode($restored->toWire(), \JSON_THROW_ON_ERROR))
            ->toBe(\json_encode($plan->toWire(), \JSON_THROW_ON_ERROR));
        Expect::that($restored->seed)->toBe(42);
        Expect::that($restored->entries[1]->id->dataSetKey)->toBe('first case');
    }

    #[Test]
    public function missingWireKeysFailLoudly(): void
    {
        $payload = new ExecutionPlan([self::entry('App\FooTest', 'a')])->toWire();
        unset($payload['seed']);

        Expect::that(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
        )->toThrow(InvalidWirePayload::class);

        $payload = new ExecutionPlan([self::entry('App\FooTest', 'a')])->toWire();
        unset($payload['entries']);

        Expect::that(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
        )->toThrow(InvalidWirePayload::class);
    }
}
