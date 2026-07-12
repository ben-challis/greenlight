<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Declares that this test legitimately verifies no expectations (it passes
 * by not throwing), so risky-test detection and the fail-on-risky policy
 * leave it alone.
 *
 * The attribute states the intent explicitly where a bare zero-assertion test
 * would be indistinguishable from a forgotten one.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class NoExpectations {}
