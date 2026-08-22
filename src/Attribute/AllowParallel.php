<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Allows Greenlight to assign tests from one class to different workers.
 *
 * Do not use this attribute with per-class harness services or `#[Isolated]`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AllowParallel {}
