<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Resolvers run in registration order and return null to pass. A returned
 * object must satisfy the requested type.
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
