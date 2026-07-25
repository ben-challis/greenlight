<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

/**
 * Owns pending work and local named-resource capacity for one run.
 *
 * The oldest blocked unit reserves one future slot on every resource it
 * needs. Later units may bypass it only with disjoint or excess capacity,
 * preventing starvation without imposing global head-of-line blocking.
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
        private array $pooled,
        private array $isolated,
        private array $limits,
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
     * @param list<SchedulingUnit> $queue
     */
    private function dispatchFrom(array &$queue): DispatchDecision
    {
        if ($this->fits($queue[0])) {
            return DispatchDecision::assign($this->acquire($queue, 0));
        }

        $reserved = \array_fill_keys($queue[0]->resources, 1);

        foreach ($queue as $index => $unit) {
            if ($index === 0) {
                continue;
            }

            if ($this->fits($unit, $reserved)) {
                return DispatchDecision::assign($this->acquire($queue, $index));
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
     * @param list<SchedulingUnit> $queue
     */
    private function acquire(array &$queue, int $index): ResourceLease
    {
        $unit = $queue[$index];
        \array_splice($queue, $index, 1);

        foreach ($unit->resources as $resource) {
            $used = ($this->inUse[$resource] ?? 0) + 1;

            if ($used > $this->limit($resource)) {
                throw new \LogicException(\sprintf('Resource "%s" was acquired above its configured limit.', $resource));
            }

            $this->inUse[$resource] = $used;
        }

        $lease = new ResourceLease($this->nextLeaseId++, $unit);
        $this->leases[$lease->id] = $lease;

        return $lease;
    }

    /**
     * An unconfigured required resource is exclusive by default.
     *
     * @return positive-int
     */
    private function limit(string $resource): int
    {
        return $this->limits[$resource] ?? 1;
    }
}
