<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Optional ordering for subscribers: lower runs earlier, default 0, stable
 * within equal priorities.
 *
 * Experimental until the plugin API GA review.
 */
interface Prioritized
{
    public function priority(): int;
}
