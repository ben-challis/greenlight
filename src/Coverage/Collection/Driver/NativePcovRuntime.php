<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

/**
 * Runs PCOV through its extension functions.
 *
 * @internal
 */
final readonly class NativePcovRuntime implements PcovRuntime
{
    #[\Override]
    public function start(): void
    {
        \pcov\start();
    }

    /**
     * @return array<mixed>
     */
    #[\Override]
    public function collect(): array
    {
        return \pcov\collect();
    }

    #[\Override]
    public function stop(): void
    {
        \pcov\stop();
    }

    #[\Override]
    public function clear(): void
    {
        \pcov\clear();
    }
}
