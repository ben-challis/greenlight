<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BridgeAttemptEnd;

use Greenlight\Tests\Fixture\Laravel\VisitCounter;

use function Greenlight\expect;

final readonly class LaravelFreshContainerProbe
{
    public function __construct(private VisitCounter $counter) {}

    public function seesAFreshService(): void
    {
        expect($this->counter->count())->toBe(0);
    }
}
