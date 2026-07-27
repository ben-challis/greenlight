<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Exempts a test that intentionally verifies no expectations from risky-test
 * detection.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class NoExpectations {}
