<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Harness\Subclass;

class SystemClockProbe
{
    public string $label = 'system';

    public function now(): string
    {
        return 'real time';
    }

    public function relabel(string $label): void
    {
        $this->label = $label;
    }
}
