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
}
