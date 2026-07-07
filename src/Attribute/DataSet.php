<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * References a public static method on the same class that yields named data
 * sets for the test method.
 *
 * The provider must be pure; it runs at discovery time.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class DataSet
{
    /**
     * @param non-empty-string $provider
     */
    public function __construct(
        public string $provider,
    ) {}
}
