<?php

declare(strict_types=1);

namespace Greenlight\Bridge;

/**
 * Identifies the container call that failed during service resolution.
 *
 * @internal
 */
enum ContainerCall
{
    /** The container failed when it checked for the service. */
    case Has;

    /** The container failed when it returned the service. */
    case Get;
}
