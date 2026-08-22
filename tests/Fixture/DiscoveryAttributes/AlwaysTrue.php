<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributes;

use Greenlight\Condition\Condition;
use Greenlight\Doubles\Fake;

final class AlwaysTrue implements Condition, Fake
{
    #[\Override]
    public function isSatisfied(): bool
    {
        return true;
    }
}
