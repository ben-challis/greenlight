<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

/**
 * Runs one Xdebug coverage collection period.
 *
 * @internal
 */
interface XdebugRuntime
{
    public function start(int $flags): void;

    /**
     * @return array<mixed>
     */
    public function collect(): array;

    public function stop(): void;
}
