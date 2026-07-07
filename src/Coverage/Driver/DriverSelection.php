<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

/**
 * Outcome of driver selection.
 *
 * It holds either a ready driver, or a reason string explaining why coverage
 * cannot be collected. Exactly one of the two is present.
 *
 * @internal
 */
final readonly class DriverSelection
{
    private function __construct(
        public ?CoverageDriver $driver,
        public ?string $reason,
    ) {}

    public static function selected(CoverageDriver $driver): self
    {
        return new self($driver, null);
    }

    /**
     * @param non-empty-string $reason
     */
    public static function unavailable(string $reason): self
    {
        return new self(null, $reason);
    }
}
