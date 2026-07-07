<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Quiet-period debounce: a change starts (or restarts) the quiet timer, and
 * the pending run fires only once no further change has arrived for the
 * configured period. Bursts such as a branch switch coalesce into one run.
 *
 * @internal
 */
final class Debouncer
{
    private ?float $lastChangeAt = null;

    public function __construct(
        private readonly float $quietSeconds,
    ) {
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
