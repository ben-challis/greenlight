<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Greenlight calls service resolvers in registration order. If a resolver
 * returns null, Greenlight calls the next resolver. A nonnull result must have
 * the requested type.
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
     *
     * @throws ServiceResolutionFailed when the resolver cannot supply a valid service
     */
    public function resolve(string $type, array $attributes): ?object;
}
