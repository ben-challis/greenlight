<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final class Any implements ArgumentMatcher
{
    public function matches(mixed $value): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'any()';
    }
}
