<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Plugin\Prioritized;

final class PrioritizedFakeCapabilityPlugin extends FakeCapabilityPlugin implements Prioritized
{
    public function __construct(private readonly int $priority) {}

    #[\Override]
    public function priority(): int
    {
        return $this->priority;
    }
}
