<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

/**
 * Runs one PCOV coverage collection period.
 *
 * @internal
 */
interface PcovRuntime
{
    public function start(): void;

    /**
     * @return array<mixed>
     */
    public function collect(): array;

    public function stop(): void;

    public function clear(): void;
}
