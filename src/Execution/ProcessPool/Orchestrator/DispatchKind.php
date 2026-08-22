<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/**
 * The action for an idle worker.
 *
 * @internal
 */
enum DispatchKind
{
    case Assign;
    case Wait;
    case Drain;
}
