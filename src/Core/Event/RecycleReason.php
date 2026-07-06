<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

/**
 * @internal
 */
enum RecycleReason: string
{
    case TestCount = 'test-count';
    case Memory = 'memory';
    case Crash = 'crash';
}
