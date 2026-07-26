<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Runner\Resource\MachineResourceCoordinator;
use Greenlight\Runner\Resource\MachineResourcePermit;

/**
 * Controls queued work and run-scoped and machine-scoped resource capacity.
 *
 * The oldest blocked scheduling unit reserves one future slot on each
 * required resource. A later scheduling unit can proceed only with separate
 * or excess capacity. Thus, the oldest scheduling unit eventually proceeds
 * without a global queue block.
 *
 * @internal
 */
final class ResourceScheduler
{
    /**
     * @var array<non-empty-string, int<0, max>>
     */
    private array $inUse = [];

    /**
     * @var array<int, ResourceLease>
     */
    private array $leases = [];

    private int $nextLeaseId = 1;

    /**
     * @param list<SchedulingUnit> $pooled
     * @param list<SchedulingUnit> $isolated
     * @param array<non-empty-string, positive-int> $limits
     */
    public function __construct(
        /** @var array<int, SchedulingUnit> */
        private array $pooled,
        /** @var array<int, SchedulingUnit> */
        private array $isolated,
        /** @var array<non-empty-string, positive-int> */
        private array $limits,
        private readonly ?MachineResourceCoordinator $machineCoordinator = null,
    ) {}

    public function dispatch(bool $freshWorker): DispatchDecision
    {
        if ($this->pooled !== []) {
            return $this->dispatchFrom($this->pooled);
        }

        if ($this->isolated === []) {
            return DispatchDecision::drain();
        }

        if (!$freshWorker) {
            return DispatchDecision::drain();
        }

        return $this->dispatchFrom($this->isolated);
    }

    public function release(ResourceLease $lease): void
    {
        if (($this->leases[$lease->id] ?? null) !== $lease) {
            throw new \LogicException(\sprintf('Resource lease %d is unknown or has already been released.', $lease->id));
        }

        unset($this->leases[$lease->id]);

        if ($lease->machinePermit instanceof MachineResourcePermit) {
            $this->machineCoordinator?->release($lease->machinePermit);
        }

        foreach ($lease->unit->resources as $resource) {
            $used = $this->inUse[$resource] ?? 0;

            if ($used < 1) {
                throw new \LogicException(\sprintf('Resource "%s" was released below zero usage.', $resource));
            }

            $this->inUse[$resource] = $used - 1;
        }
    }

    public function requeue(SchedulingUnit $unit): void
    {
        if ($unit->isolated) {
            $this->isolated[] = $unit;
        } else {
            $this->pooled[] = $unit;
        }
    }

    public function pendingCount(): int
    {
        return \count($this->pooled) + \count($this->isolated);
    }

    public function clearPending(): void
    {
        $this->pooled = [];
        $this->isolated = [];
    }

    /**
     * @param array<int, SchedulingUnit> $queue
     */
    private function dispatchFrom(array &$queue): DispatchDecision
    {
        $firstIndex = \array_key_first($queue);

        if ($firstIndex === null) {
            return DispatchDecision::drain();
        }

        $first = $queue[$firstIndex];
        $firstMachineBlocked = false;

        if ($this->fits($first)) {
            $permit = $this->tryAcquireMachine($first);

            if ($permit !== false) {
                return DispatchDecision::assign($this->acquire($queue, $firstIndex, $permit));
            }

            $firstMachineBlocked = true;
        }

        $reserved = \array_fill_keys($first->resources, 1);

        foreach ($queue as $index => $unit) {
            if ($index === $firstIndex) {
                continue;
            }

            if ($firstMachineBlocked && \array_intersect($first->resources, $unit->resources) !== []) {
                continue;
            }

            if ($this->fits($unit, $reserved) && ($permit = $this->tryAcquireMachine($unit)) !== false) {
                return DispatchDecision::assign($this->acquire($queue, $index, $permit));
            }
        }

        return DispatchDecision::wait();
    }

    /**
     * @param array<non-empty-string, int> $reserved
     */
    private function fits(SchedulingUnit $unit, array $reserved = []): bool
    {
        foreach ($unit->resources as $resource) {
            $available = $this->limit($resource) - ($this->inUse[$resource] ?? 0) - ($reserved[$resource] ?? 0);

            if ($available < 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, SchedulingUnit> $queue
     */
    private function acquire(array &$queue, int $index, ?MachineResourcePermit $machinePermit): ResourceLease
    {
        $unit = $queue[$index];
        unset($queue[$index]);

        foreach ($unit->resources as $resource) {
            $used = ($this->inUse[$resource] ?? 0) + 1;

            if ($used > $this->limit($resource)) {
                throw new \LogicException(\sprintf('Resource "%s" was acquired above its configured limit.', $resource));
            }

            $this->inUse[$resource] = $used;
        }

        $lease = new ResourceLease($this->nextLeaseId++, $unit, $machinePermit);
        $this->leases[$lease->id] = $lease;

        return $lease;
    }

    /**
     * A required resource without configuration is exclusive by default.
     *
     * @return positive-int
     */
    private function limit(string $resource): int
    {
        return $this->limits[$resource] ?? 1;
    }

    private function tryAcquireMachine(SchedulingUnit $unit): MachineResourcePermit|false|null
    {
        return $this->machineCoordinator?->tryAcquire($unit->resources);
    }
}
