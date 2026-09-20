<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Reuses immutable method contracts across doubles from one factory.
 * The factory clears the contracts when its service scope closes.
 *
 * @internal
 */
final class MethodCallContracts
{
    /** @var array<class-string, array<non-empty-string, MethodCallContract>> */
    private array $contracts = [];

    /**
     * @param class-string $type
     * @param non-empty-string $method
     */
    public function get(string $type, string $method): MethodCallContract
    {
        return $this->contracts[$type][$method] ??= MethodCallContract::from($type, $method);
    }

    public function clear(): void
    {
        $this->contracts = [];
    }
}
