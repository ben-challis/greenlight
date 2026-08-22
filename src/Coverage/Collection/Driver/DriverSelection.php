<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

/**
 * Contains one ready driver or one reason that identifies why coverage is not available.
 *
 * @internal
 */
final readonly class DriverSelection
{
    private function __construct(public ?CoverageDriver $driver, public ?string $reason) {}

    public static function selected(CoverageDriver $driver): self
    {
        return new self($driver, null);
    }

    /**
     * @throws \InvalidArgumentException when the reason is empty
     */
    public static function unavailable(string $reason): self
    {
        if ($reason === '') {
            throw new \InvalidArgumentException('Coverage unavailability requires a nonempty reason.');
        }

        return new self(null, $reason);
    }
}
