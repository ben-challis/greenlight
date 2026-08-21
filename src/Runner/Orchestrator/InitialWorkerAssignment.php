<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

/**
 * Selects when the initial worker cohort can receive assignments.
 *
 * @internal
 */
enum InitialWorkerAssignment
{
    case Progressive;
    case AfterAllReady;
}
