<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Defines the resolved test-class order for one command.
 *
 * @internal
 */
final readonly class RunOrder
{
    /** @param int|null $seed A null value keeps declared order. */
    public function __construct(public ?int $seed = null) {}

    public function isRandomized(): bool
    {
        return $this->seed !== null;
    }
}
