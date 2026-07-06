<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Marks a public method as a test.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Test {}
