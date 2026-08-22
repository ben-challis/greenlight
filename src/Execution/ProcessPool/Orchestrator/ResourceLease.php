<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/**
 * Contains the resource slots for one assigned scheduling unit.
 *
 * @internal
 */
final readonly class ResourceLease
{
    public function __construct(
        public int $id,
        public SchedulingUnit $unit,
    ) {}
}
