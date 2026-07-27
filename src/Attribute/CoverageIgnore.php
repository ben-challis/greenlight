<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Excludes the selected lines from coverage totals, coverage exports, and
 * baseline diffs.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final readonly class CoverageIgnore {}
