<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/**
 * Satisfied when the named environment variable is set to exactly the given
 * value.
 */
final readonly class EnvironmentVariableEquals implements Condition
{
    public function __construct(
        private string $name,
        private string $value,
    ) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \getenv($this->name) === $this->value;
    }
}
