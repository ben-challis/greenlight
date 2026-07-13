<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Excludes the class, method, or function from coverage. Ignored lines are
 * removed from both the covered and executable totals, so they affect no
 * percentage, export, or baseline diff.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final readonly class CoverageIgnore {}
