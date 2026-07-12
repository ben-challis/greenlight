<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Mutable per-worker accumulation for the run profile.
 *
 * spawned(), classStarted(), and classFinished() apply the corresponding
 * events; classFinished() returns the closed class span so the caller can
 * attribute it to the class, or null when no window was open.
 *
 * bootLatency(), window(), and utilisationPercent() derive the profile
 * numbers, each returning null (or a zero window) when the events needed to
 * compute them never arrived.
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

    public int $recycled = 0;

    public function spawned(float $at): void
    {
        $this->spawnedAt ??= $at;
    }

    public function classStarted(float $at): void
    {
        $this->openAt = $at;
        $this->firstClassAt ??= $at;
    }

    public function classFinished(float $at): ?float
    {
        $span = null;

        if ($this->openAt !== null) {
            $span = \max(0.0, $at - $this->openAt);
            $this->busy += $span;
            $this->openAt = null;
        }

        ++$this->classes;
        $this->lastFinishAt = $at;

        return $span;
    }

    /**
     * Spawn to first class start, or null when either end is unknown.
     */
    public function bootLatency(): ?float
    {
        if ($this->spawnedAt === null || $this->firstClassAt === null) {
            return null;
        }

        return \max(0.0, $this->firstClassAt - $this->spawnedAt);
    }

    /**
     * Spawn (or first class, whichever is known) to last class finish.
     */
    public function window(): float
    {
        $start = $this->spawnedAt ?? $this->firstClassAt;

        if ($start === null || $this->lastFinishAt === null) {
            return 0.0;
        }

        return \max(0.0, $this->lastFinishAt - $start);
    }

    /**
     * Busy time over the window as a whole percentage, capped at 100, or
     * null when the window is empty.
     */
    public function utilisationPercent(): ?int
    {
        $window = $this->window();

        if ($window <= 0.0) {
            return null;
        }

        return (int) \round(100 * \min(1.0, $this->busy / $window));
    }
}
