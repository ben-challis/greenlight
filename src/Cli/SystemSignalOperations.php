<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/** @internal */
final readonly class SystemSignalOperations implements SignalOperations
{
    #[\Override]
    public function available(): bool
    {
        return \function_exists('pcntl_signal') && \function_exists('pcntl_async_signals');
    }

    #[\Override]
    public function enableAsync(): void
    {
        \pcntl_async_signals(true);
    }

    #[\Override]
    public function register(int $signal, callable|int $handler): void
    {
        \pcntl_signal($signal, $handler);
    }
}
