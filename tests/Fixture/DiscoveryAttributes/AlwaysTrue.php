<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributes;

use Greenlight\Core\Condition;

final class AlwaysTrue implements Condition
{
    #[\Override]
    public function isSatisfied(): bool
    {
        return true;
    }
}
