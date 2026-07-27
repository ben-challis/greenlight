<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Combines multiple changes in one quiet period.
 *
 * noteChange() starts the quiet timer again. shouldFire() permits one run
 * after the configured period has no new changes. Thus, a group of changes,
 * such as a branch switch, causes one run.
 *
 * @internal
 */
final class Debouncer
{
    private ?float $lastChangeAt = null;

    public function __construct(private readonly float $quietSeconds)
    {
        if ($quietSeconds < 0.0) {
            throw new \InvalidArgumentException('The quiet period cannot be negative.');
        }
    }

    public function noteChange(float $now): void
    {
        $this->lastChangeAt = $now;
    }

    public function shouldFire(float $now): bool
    {
        return $this->lastChangeAt !== null && $now - $this->lastChangeAt >= $this->quietSeconds;
    }

    public function reset(): void
    {
        $this->lastChangeAt = null;
    }
}
