<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Optional ordering for subscribers: lower runs earlier, default 0, stable
 * within equal priorities.
 */
interface Prioritized
{
    public function priority(): int;
}
