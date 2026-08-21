<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Discovery\ExecutionPlan;

/**
 * Produces bounded pooled scheduling units and one-entry isolated units.
 *
 * By default, classes in one pooled unit have the same resource set. A pooled
 * unit has a maximum predicted duration and class count. The distributor also
 * keeps enough pooled units to use all requested workers. Opted-in entries and
 * isolated entries remain one-entry units.
 *
 * @internal
 */
final readonly class Distributor
{
    private const float TARGET_BATCH_SECONDS = 0.05;

    private const int MAX_BATCH_CLASSES = 16;

    /**
     * @param array<string, float> $classSeconds Recorded class durations.
     * @param positive-int $workerCount
     *
     * @return array{list<SchedulingUnit>, list<SchedulingUnit>} pooled units in plan order, then isolated single-entry units
     */
    public function units(ExecutionPlan $plan, array $classSeconds = [], int $workerCount = 1): array
    {
        $pooled = [];
        $isolated = [];
        $batchable = [];

        foreach ($plan->entriesByClass() as $class => $entries) {
            $pooledEntries = [];
            $hasUnbatchableEntry = false;

            foreach ($entries as $entry) {
                if ($entry->metadata->isolated) {
                    $hasUnbatchableEntry = true;
                    $isolated[] = new SchedulingUnit(new ExecutionPlan([$entry], $plan->seed), true);
                } elseif ($entry->metadata->allowParallel) {
                    $hasUnbatchableEntry = true;
                    $pooled[] = new SchedulingUnit(new ExecutionPlan([$entry], $plan->seed), false);
                } else {
                    $pooledEntries[] = $entry;
                }
            }

            if ($pooledEntries !== []) {
                $pooled[] = new SchedulingUnit(new ExecutionPlan($pooledEntries, $plan->seed), false);
                $batchable[$class] = !$hasUnbatchableEntry;
            }
        }

        if ($classSeconds === []) {
            return [$pooled, $isolated];
        }

        return [$this->batch($pooled, $classSeconds, $batchable, $workerCount), $isolated];
    }

    /**
     * @param list<SchedulingUnit> $units
     * @param array<string, float> $classSeconds
     * @param array<non-empty-string, bool> $batchable
     * @param positive-int $workerCount
     *
     * @return list<SchedulingUnit>
     */
    private function batch(array $units, array $classSeconds, array $batchable, int $workerCount): array
    {
        $batched = [];
        $compatible = [];

        foreach ($units as $unit) {
            $class = $unit->plan->entries[0]->id->class;
            $seconds = $classSeconds[$class] ?? null;

            if (($batchable[$class] ?? false) !== true
                || !\is_float($seconds)
                || !\is_finite($seconds)
                || $seconds < 0.0
                || $seconds > self::TARGET_BATCH_SECONDS
            ) {
                $this->flushCompatible($batched, $compatible, $workerCount);
                $batched[] = $unit;

                continue;
            }

            if ($compatible !== [] && !$this->hasSameResources($compatible[0][0], $unit)) {
                $this->flushCompatible($batched, $compatible, $workerCount);
            }

            $compatible[] = [$unit, $seconds];
        }

        $this->flushCompatible($batched, $compatible, $workerCount);

        return $batched;
    }

    /**
     * @param list<SchedulingUnit> $batched
     * @param list<array{SchedulingUnit, float}> $compatible
     * @param positive-int $workerCount
     */
    private function flushCompatible(array &$batched, array &$compatible, int $workerCount): void
    {
        if ($compatible === []) {
            return;
        }

        $pending = [];
        $pendingSeconds = 0.0;
        $remainingMerges = \max(0, \count($compatible) - $workerCount);

        foreach ($compatible as [$unit, $seconds]) {
            if ($pending !== [] && (
                $remainingMerges === 0
                || \count($pending) >= self::MAX_BATCH_CLASSES
                || $pendingSeconds + $seconds > self::TARGET_BATCH_SECONDS
            )) {
                $this->flushBatch($batched, $pending);
                $pendingSeconds = 0.0;
            }

            if ($pending !== [] && $remainingMerges > 0) {
                --$remainingMerges;
            }

            $pending[] = $unit;
            $pendingSeconds += $seconds;
        }

        $this->flushBatch($batched, $pending);
        $compatible = [];
    }

    /**
     * @param list<SchedulingUnit> $batched
     * @param list<SchedulingUnit> $pending
     */
    private function flushBatch(array &$batched, array &$pending): void
    {
        if (\count($pending) === 1) {
            $batched[] = $pending[0];
            $pending = [];

            return;
        }

        $entries = [];

        foreach ($pending as $unit) {
            $entries = [...$entries, ...$unit->plan->entries];
        }

        $batched[] = new SchedulingUnit(
            new ExecutionPlan($entries, $pending[0]->plan->seed),
            false,
        );
        $pending = [];
    }

    private function hasSameResources(SchedulingUnit $left, SchedulingUnit $right): bool
    {
        $leftResources = $left->resources;
        $rightResources = $right->resources;
        \sort($leftResources);
        \sort($rightResources);

        return $leftResources === $rightResources;
    }
}
