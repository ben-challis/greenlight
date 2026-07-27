<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/** Greenlight captures test output by default. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Test
{
    public function __construct(public bool $capture = true) {}
}
