<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Allows Greenlight to assign tests from one class to different workers.
 *
 * The class MUST NOT use per-class harness services or `#[Isolated]`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AllowParallel {}
