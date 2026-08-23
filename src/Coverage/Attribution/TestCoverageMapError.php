<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Attribution;

/**
 * A per-test coverage map is missing, stale, or not valid for impact selection.
 *
 * @internal
 */
final class TestCoverageMapError extends \RuntimeException {}
