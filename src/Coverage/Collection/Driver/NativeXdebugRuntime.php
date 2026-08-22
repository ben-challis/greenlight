<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

/**
 * Calls the native Xdebug coverage functions.
 *
 * @internal
 */
final readonly class NativeXdebugRuntime implements XdebugRuntime
{
    #[\Override]
    public function start(int $flags): void
    {
        \xdebug_start_code_coverage($flags);
    }

    #[\Override]
    public function collect(): array
    {
        return \xdebug_get_code_coverage();
    }

    #[\Override]
    public function stop(): void
    {
        \xdebug_stop_code_coverage();
    }
}
