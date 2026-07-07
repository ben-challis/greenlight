<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Detects filesystem changes between polls. The stat-based implementation is
 * the portable default; native backends can slot in behind this interface
 * without touching the loop.
 *
 * @internal
 */
interface ChangeDetector
{
    /**
     * @return list<non-empty-string> paths changed since the previous poll
     */
    public function poll(): array;
}
