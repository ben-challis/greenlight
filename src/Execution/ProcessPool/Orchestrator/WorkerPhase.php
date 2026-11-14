<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/**
 * Names the lifecycle phase of one worker in the orchestrator.
 *
 * @internal
 */
enum WorkerPhase
{
    /** The process started. The worker did not authenticate. */
    case Spawned;

    /** The worker authenticated and received Bootstrap. It did not report Ready. */
    case Bootstrapping;

    /** The worker is ready and has no assignment. */
    case Idle;

    /** The worker runs an assignment. */
    case Assigned;

    /** The worker received Drain. It can still finish its current assignment. */
    case Draining;

    /** The transport stops the process. */
    case Retiring;
}
