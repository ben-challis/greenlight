<?php

declare(strict_types=1);

namespace App;

final class VisitCounter
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
