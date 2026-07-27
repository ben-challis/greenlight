<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * A runtime condition that `#[SkipUnless]` references.
 *
 * Implementations can receive constructor arguments from `#[SkipUnless]`.
 * Constructors must only store these arguments. `isSatisfied()` evaluates the
 * condition and must not cause other changes.
 */
interface Condition
{
    public function isSatisfied(): bool;
}
