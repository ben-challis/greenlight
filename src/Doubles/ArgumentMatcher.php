<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * A per-position argument constraint for with().
 *
 * matches() decides whether a value is acceptable in the matcher's position;
 * it must be side-effect free, because candidate expectations are probed
 * against calls they may not win. describe() names the constraint in failure
 * messages.
 *
 * Obtain matchers via the Argument factories.
 */
interface ArgumentMatcher
{
    public function matches(mixed $value): bool;

    public function describe(): string;
}
