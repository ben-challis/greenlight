<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Skips;

use Greenlight\Condition\Condition;
use Greenlight\Doubles\Fake;

final class NeverCondition implements Condition, Fake
{
    #[\Override]
    public function isSatisfied(): bool
    {
        return false;
    }
}
