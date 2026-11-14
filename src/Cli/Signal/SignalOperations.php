<?php

declare(strict_types=1);

namespace Greenlight\Cli\Signal;

/**
 * Provides process signal operations to SignalHandlers.
 *
 * @internal
 */
interface SignalOperations
{
    public function available(): bool;

    public function enableAsync(): void;

    /** @param (callable(int): void)|int $handler */
    public function register(int $signal, callable|int $handler): void;

    /**
     * Sends the signal to the current process. The registered handler for the
     * signal decides the result.
     */
    public function raise(int $signal): void;
}
