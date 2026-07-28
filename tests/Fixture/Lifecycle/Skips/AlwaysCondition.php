<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Skips;

use Greenlight\Core\Condition;
use Greenlight\Doubles\Fake;

final class AlwaysCondition implements Condition, Fake
{
    #[\Override]
    public function isSatisfied(): bool
    {
        return true;
    }
}
