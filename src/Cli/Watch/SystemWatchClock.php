<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * @internal
 */
final readonly class SystemWatchClock implements WatchClock
{
    #[\Override]
    public function now(): float
    {
        return \microtime(true);
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        \usleep((int) \round($seconds * 1_000_000));
    }
}
