<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/**
 * Identifies the process retirement stage for one worker handle.
 *
 * @internal
 */
enum WorkerLifecycle
{
    case Active;
    case Retiring;
    case Killing;
    case Reaped;
}
