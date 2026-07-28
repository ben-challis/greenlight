<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributes;

use Greenlight\Core\Condition;
use Greenlight\Doubles\Fake;

final class AlwaysFalse implements Condition, Fake
{
    #[\Override]
    public function isSatisfied(): bool
    {
        return false;
    }
}
