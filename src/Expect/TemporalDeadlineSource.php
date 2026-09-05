<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Identifies the time limit that controls a temporal expectation.
 *
 * @internal
 */
enum TemporalDeadlineSource
{
    case Local;
    case Test;
    case Enclosing;
}
