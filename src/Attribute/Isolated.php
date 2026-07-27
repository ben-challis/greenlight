<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Greenlight assigns a new worker to each selected test.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Isolated {}
