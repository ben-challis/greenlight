<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Argument wildcard: matches any value in a with() position.
 *
 * Obtain one via MockPlan::any() or Argument::any(); treat the type itself
 * as opaque.
 *
 * @internal
 */
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
