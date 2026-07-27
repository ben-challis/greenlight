<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/** Compares the value to `PHP_OS_FAMILY` without case sensitivity. */
final readonly class OperatingSystemFamily implements Condition
{
    public function __construct(private string $family) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \strcasecmp($this->family, \PHP_OS_FAMILY) === 0;
    }
}
