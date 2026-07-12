<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Fallback resolution for constructor parameter types no harness service
 * covers.
 *
 * When constructor injection meets a type without a registered
 * ServiceDefinition, each plugin implementing this interface is asked in
 * registration order. resolve() receives the parameter's declared type and
 * its instantiated attributes, and returns the service or null to pass. A
 * resolver that answers must return an instance of the requested type;
 * anything else fails the test loudly.
 *
 * Objects supplied this way are not tracked by harness scopes: Greenlight
 * never disposes them, so their lifecycle belongs to whatever produced them.
 */
interface ServiceResolver
{
    /**
     * @param class-string $type
     * @param list<object> $attributes
     */
    public function resolve(string $type, array $attributes): ?object;
}
