<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/** References a pure public static provider that runs during discovery. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class DataSet
{
    /**
     * @param non-empty-string $provider
     */
    public function __construct(public string $provider) {}
}
