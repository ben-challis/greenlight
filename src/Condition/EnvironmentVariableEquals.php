<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Internal\Process\EnvironmentVariableName;

/** Passes when `getenv()` returns the exact expected string for the variable. */
final readonly class EnvironmentVariableEquals implements Condition
{
    /**
     * @var non-empty-string
     */
    private string $name;

    /**
     * @param non-empty-string $name
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $name, private string $value)
    {
        EnvironmentVariableName::assertValid($name);

        $this->name = $name;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \getenv($this->name) === $this->value;
    }
}
