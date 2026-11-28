<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Harness\Subclass;

final class FrozenClockProbe extends SystemClockProbe
{
    public string $frozenAt = 'frozen time';

    #[\Override]
    public function now(): string
    {
        return $this->frozenAt;
    }
}
