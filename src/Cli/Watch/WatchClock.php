<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Time source for the watch loop.
 *
 * Injectable so debounce behaviour is tested with virtual time, never with
 * sleeps.
 *
 * @internal
 */
interface WatchClock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}
