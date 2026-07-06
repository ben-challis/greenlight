<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Skips the test method, or every test in the class, unconditionally.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Skip
{
    /**
     * @param non-empty-string $reason
     */
    public function __construct(
        public string $reason,
    ) {}
}
