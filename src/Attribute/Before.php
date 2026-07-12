<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Marks a public method to run before each test in the class.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Before {}
