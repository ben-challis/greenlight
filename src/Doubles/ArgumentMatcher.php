<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * An argument constraint for one position in with().
 *
 * matches() determines if the matcher accepts a value in its position.
 * Candidate expectations can receive checks for calls that they do not
 * answer. Thus, matches() must not cause side effects. describe() identifies
 * the constraint in failure messages.
 *
 * Use the Argument factories to get matchers.
 */
interface ArgumentMatcher
{
    public function matches(mixed $value): bool;

    public function describe(): string;
}
