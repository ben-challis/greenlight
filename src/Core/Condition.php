<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Execution-time condition referenced by #[SkipUnless]. Implementations must be
 * constructible without arguments and side-effect free.
 */
interface Condition
{
    public function isSatisfied(): bool;
}
