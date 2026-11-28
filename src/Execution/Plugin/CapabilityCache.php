<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Plugin\Plugin;

/**
 * Holds the ordered plugins of one runtime for each capability. A readonly
 * runtime keeps this object to filter each capability one time.
 *
 * @internal
 */
final class CapabilityCache
{
    /** @var array<class-string, list<Plugin>> */
    public array $ordered = [];

    /** @var array<class-string, list<Plugin>> */
    public array $reversed = [];
}
