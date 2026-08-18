<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support\Psr;

use Psr\Container\ContainerInterface;

/**
 * Stores fixed PSR-11 service values for integration tests.
 *
 * @internal
 */
final readonly class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(private array $services) {}

    #[\Override]
    public function get(string $id): mixed
    {
        if (!\array_key_exists($id, $this->services)) {
            throw new \RuntimeException(\sprintf('Service "%s" does not exist.', $id));
        }

        return $this->services[$id];
    }

    #[\Override]
    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->services);
    }
}
