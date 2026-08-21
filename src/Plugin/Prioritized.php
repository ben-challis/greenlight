<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Controls order within each plugin capability. Capability interfaces define
 * whether callbacks use or reverse this order. The default value is zero.
 * The base order puts lower values first and keeps registration order for
 * equal values.
 */
interface Prioritized
{
    public function priority(): int;
}
