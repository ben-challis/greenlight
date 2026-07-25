<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/** @internal */
interface WatchClock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}
