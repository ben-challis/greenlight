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

        Expect::that($plan)->because('exposes entries classes and counts')->toHaveCount(3);
        Expect::that($plan->classes())->because('exposes entries classes and counts')->toBe(['App\FooTest', 'App\BarTest']);
        Expect::that($plan->seed)->because('exposes entries classes and counts')->toBe(7);

        $byClass = $plan->entriesByClass();

        Expect::that($byClass['App\FooTest'])->because('exposes entries classes and counts')->toHaveCount(2);
        Expect::that($byClass['App\BarTest'])->because('exposes entries classes and counts')->toHaveCount(1);
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
        )->because('rejects entries not grouped by class')->toThrow(
            \InvalidArgumentException::class,
            message: 'Group execution plan entries by class. "App\FooTest" occurs in more than one block.',
        );
    }

    #[Test]
    public function rejectsEntryWhoseIdAndMetadataDisagree(): void
    {
        Expect::that(
            static fn(): PlanEntry => new PlanEntry(
                new TestId('App\FooTest', 'a'),
                new TestMetadata('App\FooTest', 'b'),
            ),
        )->because('rejects entry whose ID and metadata disagree')->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function survivesTheWire(): void
    {
        $plan = new ExecutionPlan([
            self::entry('App\FooTest', 'a'),
            self::entry('App\FooTest', 'b', 'first case'),
        ], 42);

        $restored = ExecutionPlan::fromWire(JsonWire::roundTrip($plan->toWire()));

        Expect::that(\json_encode($restored->toWire(), \JSON_THROW_ON_ERROR))->because('survives the wire')
            ->toBe(\json_encode($plan->toWire(), \JSON_THROW_ON_ERROR));
        Expect::that($restored->seed)->because('survives the wire')->toBe(42);
        Expect::that($restored->entries[1]->id->dataSetKey)->because('survives the wire')->toBe('first case');
    }

    #[Test]
    public function missingWireKeysFailLoudly(): void
    {
        $payload = new ExecutionPlan([self::entry('App\FooTest', 'a')])->toWire();
        unset($payload['seed']);

        Expect::that(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
        )->because('missing wire keys cause an error')->toThrow(InvalidWirePayload::class);

        $payload = new ExecutionPlan([self::entry('App\FooTest', 'a')])->toWire();
        unset($payload['entries']);

        Expect::that(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
        )->because('missing wire keys cause an error')->toThrow(InvalidWirePayload::class);
    }
}
