<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Execution\Resource\MachineResourcePermit;

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
        public ?MachineResourcePermit $machinePermit = null,
    ) {}
}
