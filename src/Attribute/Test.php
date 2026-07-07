<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Marks a public method as a test. Output capture is on by default; disable
 * it for tests that debug output themselves.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Test
{
    public function __construct(
        public bool $capture = true,
    ) {}
}
