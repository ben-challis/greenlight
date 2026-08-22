<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;
use Greenlight\Tests\Support\PlanEntryFixture;
use Greenlight\Wire\InvalidWirePayload;

final class ExecutionPlanTest
{
    #[Test]
    public function exposesEntriesClassesAndCounts(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('App\FooTest', 'a'),
            PlanEntryFixture::create('App\FooTest', 'b', 'first'),
            PlanEntryFixture::create('App\BarTest', 'c'),
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
                PlanEntryFixture::create('App\FooTest', 'a'),
                PlanEntryFixture::create('App\BarTest', 'c'),
                PlanEntryFixture::create('App\FooTest', 'b'),
            ]),
        )->because('rejects entries not grouped by class')->toThrow(
            \InvalidArgumentException::class,
            message: 'Group execution plan entries by class. "App\FooTest" occurs in more than one block.',
        );
    }

    #[Test]
    public function rejectsDuplicateTestIdsFromConstructionAndTheWire(): void
    {
        $entry = PlanEntryFixture::create('App\FooTest', 'a', 'first');
        $message = 'Execution plan test ID "App\FooTest::a[first]" occurs more than once.';

        Expect::that(static fn(): ExecutionPlan => new ExecutionPlan([$entry, $entry]))
            ->because('an execution plan MUST contain each test ID exactly once')
            ->toThrow(\InvalidArgumentException::class, message: $message);

        $payload = [
            'seed' => null,
            'entries' => [$entry->toWire(), $entry->toWire()],
        ];

        Expect::that(static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload))
            ->because('wire decoding MUST reject duplicate test IDs')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    #[Test]
    public function survivesTheWire(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('App\FooTest', 'a'),
            PlanEntryFixture::create('App\FooTest', 'b', 'first case'),
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
        $payload = new ExecutionPlan([PlanEntryFixture::create('App\FooTest', 'a')])->toWire();
        unset($payload['seed']);

        Expect::that(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
        )->because('missing wire keys cause an error')->toThrow(InvalidWirePayload::class);

        $payload = new ExecutionPlan([PlanEntryFixture::create('App\FooTest', 'a')])->toWire();
        unset($payload['entries']);

        Expect::that(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
        )->because('missing wire keys cause an error')->toThrow(InvalidWirePayload::class);
    }
}
