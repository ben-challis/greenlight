<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner;

use Greenlight\Expect\Expect;

final readonly class OptionalConstructorProbe
{
    public function __construct(private string $value = 'declared default') {}

    public function usesDeclaredDefault(): void
    {
        Expect::that($this->value)
            ->because('the worker uses optional built-in constructor defaults')
            ->toBe('declared default');
    }
}
