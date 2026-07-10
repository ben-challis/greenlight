<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

/**
 * A clock frozen at a single instant.
 *
 * now() always returns the same DateTimeImmutable: the instant given at
 * construction, or the moment the clock was constructed. at() accepts a
 * date-time string or object for readable test setup.
 */
final readonly class FrozenClock
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable();
    }

    public static function at(\DateTimeImmutable|string $time): self
    {
        return new self(\is_string($time) ? new \DateTimeImmutable($time) : $time);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
