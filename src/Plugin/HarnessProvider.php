<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Harness\ServiceDefinition;

/**
 * Adds harness services to the worker registry.
 *
 * Greenlight adds built-in services before `services()` results. A duplicate
 * type in the same source causes a configuration error. Unnamed definitions
 * share one source. Different named sources can define the same type.
 */
interface HarnessProvider extends Plugin
{
    /**
     * @return list<ServiceDefinition>
     */
    public function services(): array;
}
