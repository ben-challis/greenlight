<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

/**
 * If the parameter type is not a unique container ID, use `#[Service]`. The
 * resolved service must have the declared type.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Service
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(public string $id) {}
}
