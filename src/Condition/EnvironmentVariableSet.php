<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/**
 * Satisfied when the named environment variable is set, whatever its value.
 */
final readonly class EnvironmentVariableSet implements Condition
{
    public function __construct(
        private string $name,
    ) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \getenv($this->name) !== false;
    }
}
