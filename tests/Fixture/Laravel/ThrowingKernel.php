<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Laravel;

use Illuminate\Contracts\Console\Kernel;

/**
 * Throws when the bridge starts the console kernel.
 */
final class ThrowingKernel implements Kernel
{
    #[\Override]
    public function bootstrap(): void
    {
        throw new \RuntimeException('The fixture kernel could not bootstrap.');
    }

    #[\Override]
    public function handle(mixed $input, mixed $output = null): int
    {
        return 0;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function call(mixed $command, array $parameters = [], mixed $outputBuffer = null): int
    {
        return 0;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function queue(mixed $command, array $parameters = []): never
    {
        throw new \LogicException('The fixture kernel cannot queue a command.');
    }

    /**
     * @return array<never, never>
     */
    #[\Override]
    public function all(): array
    {
        return [];
    }

    #[\Override]
    public function output(): string
    {
        return '';
    }

    #[\Override]
    public function terminate(mixed $input, mixed $status): void {}
}
