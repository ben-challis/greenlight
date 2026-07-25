<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

use Symfony\Contracts\Service\ResetInterface;

/**
 * ResetInterface lets services_resetter clear state between tests.
 */
final class VisitCounter implements ResetInterface
{
    private int $visits = 0;

    public function record(): void
    {
        ++$this->visits;
    }

    public function count(): int
    {
        return $this->visits;
    }

    #[\Override]
    public function reset(): void
    {
        $this->visits = 0;
    }
}
