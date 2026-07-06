<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Marks a public method to run after each test in the class, including after failures.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class After {}
