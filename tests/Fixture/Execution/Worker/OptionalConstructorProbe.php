<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker;



use function Greenlight\expect;

final readonly class OptionalConstructorProbe
{
    public function __construct(private string $value = 'declared default') {}

    public function usesDeclaredDefault(): void
    {
        expect($this->value)
            ->because('the worker uses optional built-in constructor defaults')
            ->toBe('declared default');
    }
}
