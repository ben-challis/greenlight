<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

/**
 * Identifies an orchestrator-visible cause for worker idle time.
 *
 * @internal
 */
enum WorkerIdleReason
{
    case BootstrapBarrier;
    case ResourceCapacity;
    case NoQueuedWork;
}
