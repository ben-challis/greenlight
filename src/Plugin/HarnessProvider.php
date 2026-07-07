<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Harness\ServiceDefinition;

/**
 * Contributes injectable scoped services to the worker registry, merged after
 * the built-ins. Registering a type twice is a configuration error.
 *
 * Experimental until the plugin API GA review.
 */
interface HarnessProvider
{
    /**
     * @return list<ServiceDefinition>
     */
    public function services(): array;
}
