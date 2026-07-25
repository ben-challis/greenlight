<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

/**
 * The action an idle worker should take.
 *
 * @internal
 */
enum DispatchKind
{
    case Assign;
    case Wait;
    case Drain;
}
