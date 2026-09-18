<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Greenlight calls service resolvers with lower priorities first. Equal
 * priorities use registration order. A terminal resolver runs after all other
 * resolvers, regardless of priority.
 *
 * A null result asks Greenlight to call the next resolver. An object must have
 * the requested type. A `ServiceResolutionFailed` exception stops resolution.
 *
 * Objects from a service resolver do not belong to a harness service scope.
 * Greenlight does not dispose them. The source of an object controls its
 * lifetime.
 */
interface ServiceResolver
{
    /**
     * @param class-string $type
     * @param list<object> $attributes
     * @throws ServiceResolutionFailed when the resolver handles the request but cannot supply a valid service
     */
    public function resolve(string $type, array $attributes): ?object;
}
