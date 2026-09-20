<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

use Psr\Container\ContainerInterface;

/**
 * Rejects access to the Hyperf container outside a test attempt.
 *
 * @internal
 */
final class UnavailableContainer implements ContainerInterface
{
    /** @throws UnavailableContainerError */
    public function get(string $id): never
    {
        throw UnavailableContainerError::outsideTestAttempt();
    }

    public function has(string $id): bool
    {
        return false;
    }
}
