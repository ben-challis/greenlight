<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Defines the configured test-class order before command-line resolution.
 *
 * @internal
 */
final readonly class OrderConfiguration
{
    /** @param int<0, max>|null $seed */
    public function __construct(
        public bool $randomized = false,
        public ?int $seed = null,
    ) {}

    /** @param int<0, max>|null $overrideSeed */
    public function resolve(?int $overrideSeed): RunOrder
    {
        if ($overrideSeed !== null) {
            return new RunOrder($overrideSeed);
        }

        if (!$this->randomized) {
            return new RunOrder();
        }

        return new RunOrder($this->seed ?? \random_int(0, 2 ** 31 - 1));
    }
}
