<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Profile;

/**
 * Contains the measured lifecycle and utilization values for one worker.
 *
 * @internal
 */
final class WorkerProfile
{
    public ?float $spawnedAt = null;

    public float $busy = 0.0;

    public int $classes = 0;

    public ?float $openAt = null;

    public ?float $firstClassAt = null;

    public ?float $lastFinishAt = null;

    public bool $isolated = false;

    public function spawned(float $at): void
    {
        $this->spawnedAt ??= $at;
    }

    public function classStarted(float $at, bool $isolated = false): void
    {
        $this->openAt = $at;
        $this->firstClassAt ??= $at;
        $this->isolated = $this->isolated || $isolated;
    }

    public function classFinished(float $at): ?float
    {
        $span = null;

        if ($this->openAt !== null) {
            $span = ProfileDuration::between($this->openAt, $at);
            $this->busy = ProfileDuration::add($this->busy, $span);
            $this->openAt = null;
        }

        ++$this->classes;
        $this->lastFinishAt = $at;

        return $span;
    }

    /**
     * Returns boot latency from worker-process creation to the first
     * test-class start.
     *
     * Returns null if either timestamp is unknown.
     */
    public function bootLatency(): ?float
    {
        if ($this->spawnedAt === null || $this->firstClassAt === null) {
            return null;
        }

        return ProfileDuration::between($this->spawnedAt, $this->firstClassAt);
    }

    /**
     * Returns the time from process start to the completion of the last test class.
     *
     * If process start is unknown, uses the first test-class start.
     */
    public function window(): float
    {
        $start = $this->spawnedAt ?? $this->firstClassAt;

        if ($start === null || $this->lastFinishAt === null) {
            return 0.0;
        }

        return ProfileDuration::between($start, $this->lastFinishAt);
    }

    /**
     * Returns busy time as a percentage of the worker period.
     *
     * The maximum is 100. Returns null for an empty period.
     */
    public function utilizationPercent(): ?int
    {
        $window = $this->window();

        if ($window <= 0.0) {
            return null;
        }

        return (int) \round(100 * \min(1.0, $this->busy / $window));
    }

}
