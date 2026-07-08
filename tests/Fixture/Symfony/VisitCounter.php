<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Stateful container service proving reset-between-tests semantics.
 *
 * Autoconfiguration tags ResetInterface implementations for the
 * services_resetter, so a bridge afterTest() call must bring count() back
 * to zero.
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
