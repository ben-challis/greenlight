<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Laravel;

use Greenlight\Doubles\Fake;

/**
 * Stateful singleton with no reset contract. The fresh application for each
 * test is the only isolation mechanism.
 */
final class VisitCounter implements Fake
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
}
