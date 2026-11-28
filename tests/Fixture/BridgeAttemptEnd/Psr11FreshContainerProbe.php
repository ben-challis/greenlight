<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BridgeAttemptEnd;

use function Greenlight\expect;

final readonly class Psr11FreshContainerProbe
{
    public function __construct(private AttemptCounter $counter) {}

    public function seesAFreshService(): void
    {
        expect($this->counter->value)->toBe(0);
    }
}
