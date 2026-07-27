<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Controls order within each plugin capability. Lower values run earlier.
 * The default value is zero. Equal values keep their original order.
 */
interface Prioritized
{
    public function priority(): int;
}
