<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Harness\ServiceDefinition;

/**
 * Contributes injectable scoped services to the worker registry.
 *
 * services() results are merged after the built-ins. Registering a type twice
 * is a configuration error.
 */
interface HarnessProvider
{
    /**
     * @return list<ServiceDefinition>
     */
    public function services(): array;
}
