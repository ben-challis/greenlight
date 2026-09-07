<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Assigns a fresh worker process to each selected test during process-pool
 * execution. In-process execution does not provide process isolation.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Isolated {}
