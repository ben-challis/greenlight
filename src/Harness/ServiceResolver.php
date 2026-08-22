<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Greenlight calls service resolvers in registration order. An unhandled
 * result asks Greenlight to call the next resolver. A resolved result must
 * contain the requested type. A failed result stops resolution.
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
     */
    public function resolve(string $type, array $attributes): ServiceResolution;
}
