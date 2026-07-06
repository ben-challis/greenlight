<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Tests\Support\Check;

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

        Check::same(3, \count($plan), 'plan count');
        Check::same(['App\FooTest', 'App\BarTest'], $plan->classes(), 'classes in plan order');
        Check::same(7, $plan->seed, 'seed');

        $byClass = $plan->entriesByClass();

        Check::same(2, \count($byClass['App\FooTest']), 'entries for FooTest');
        Check::same(1, \count($byClass['App\BarTest']), 'entries for BarTest');
    }

    #[Test]
    public function rejectsEntriesNotGroupedByClass(): void
    {
        Check::throws(
            static fn(): ExecutionPlan => new ExecutionPlan([
                self::entry('App\FooTest', 'a'),
                self::entry('App\BarTest', 'c'),
                self::entry('App\FooTest', 'b'),
            ]),
            \InvalidArgumentException::class,
            'plan with interleaved classes',
        );
    }

    #[Test]
    public function rejectsEntryWhoseIdAndMetadataDisagree(): void
    {
        Check::throws(
            static fn(): PlanEntry => new PlanEntry(
                new TestId('App\FooTest', 'a'),
                new TestMetadata('App\FooTest', 'b'),
            ),
            \InvalidArgumentException::class,
            'entry with mismatched id and metadata',
        );
    }

    #[Test]
    public function survivesTheWire(): void
    {
        $plan = new ExecutionPlan([
            self::entry('App\FooTest', 'a'),
            self::entry('App\FooTest', 'b', 'first case'),
        ], 42);

        $restored = ExecutionPlan::fromWire(Check::jsonRoundTrip($plan->toWire()));

        Check::same(
            \json_encode($plan->toWire(), \JSON_THROW_ON_ERROR),
            \json_encode($restored->toWire(), \JSON_THROW_ON_ERROR),
            'plan after a wire round trip',
        );
        Check::same(42, $restored->seed, 'restored seed');
        Check::same('first case', $restored->entries[1]->id->dataSetKey, 'restored data-set key');
    }

    #[Test]
    public function missingWireKeysFailLoudly(): void
    {
        $payload = new ExecutionPlan([self::entry('App\FooTest', 'a')])->toWire();
        unset($payload['seed']);

        Check::throws(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
            InvalidWirePayload::class,
            'payload missing seed',
        );

        $payload = new ExecutionPlan([self::entry('App\FooTest', 'a')])->toWire();
        unset($payload['entries']);

        Check::throws(
            static fn(): ExecutionPlan => ExecutionPlan::fromWire($payload),
            InvalidWirePayload::class,
            'payload missing entries',
        );
    }
}
