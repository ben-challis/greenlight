<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Removes ignored lines from covered and executable totals, exports, and
 * baseline diffs.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final readonly class CoverageIgnore {}
