<?php

declare(strict_types=1);

namespace Greenlight\Condition;

/** Passes when `function_exists()` finds the named function. */
final readonly class FunctionAvailable implements Condition
{
    /**
     * @var non-empty-string
     */
    private string $function;

    /**
     * @param non-empty-string $function
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $function)
    {
        if ($function === '') {
            throw new \InvalidArgumentException('Function name MUST NOT be empty.');
        }

        $this->function = $function;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \function_exists($this->function);
    }
}
