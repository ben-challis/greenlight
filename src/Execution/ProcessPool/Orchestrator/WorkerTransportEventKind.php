<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/**
 * Identifies one observation from a worker transport.
 *
 * @internal
 */
enum WorkerTransportEventKind
{
    case ConnectionAccepted;
    case ConnectionClosed;
    case ConnectionMessage;
    case WorkerMessage;
    case WorkerDisconnected;
    case WorkerRetired;
}
