<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Harness\ServiceDefinition;

/**
 * Adds harness services to the worker registry.
 *
 * Greenlight adds built-in services before services() results. A duplicate
 * type causes a configuration error.
 */
interface HarnessProvider extends Plugin
{
    /**
     * @return list<ServiceDefinition>
     */
    public function services(): array;
}
