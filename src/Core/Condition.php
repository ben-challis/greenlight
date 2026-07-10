<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Execution-time condition referenced by #[SkipUnless].
 *
 * Implementations may take constructor arguments supplied through
 * #[SkipUnless]; constructors must only store them. Evaluation happens in
 * isSatisfied() and must be side-effect free.
 */
interface Condition
{
    public function isSatisfied(): bool;
}
