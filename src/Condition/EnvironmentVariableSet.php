<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

final readonly class EnvironmentVariableSet implements Condition
{
    /**
     * @var non-empty-string
     */
    private string $name;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $name)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Environment variable name MUST NOT be empty.');
        }

        $this->name = $name;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \getenv($this->name) !== false;
    }
}
