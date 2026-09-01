<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Internal\Process\EnvironmentVariableName;

final readonly class EnvironmentVariableSet implements Condition
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
    public function __construct(string $name)
    {
        EnvironmentVariableName::assertValid($name);

        $this->name = $name;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \getenv($this->name) !== false;
    }
}
