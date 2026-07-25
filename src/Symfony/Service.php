<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

/**
 * Use when the parameter type is not a unique container id. The resolved
 * service must still satisfy the declared type.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Service
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(public string $id) {}
}
