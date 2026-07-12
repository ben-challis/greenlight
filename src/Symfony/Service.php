<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

/**
 * Overrides type-based container lookup with an explicit Symfony service id.
 *
 * Place it on a test constructor parameter when the type alone cannot pick
 * the service: ids without a class-name alias, interfaces with several
 * implementations, or services registered only under a string name. The
 * declared parameter type is still enforced; a service that is not an
 * instance of it fails the test loudly.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Service
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(public string $id) {}
}
