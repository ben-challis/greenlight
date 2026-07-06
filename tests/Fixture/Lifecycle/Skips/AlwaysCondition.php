<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Skips;

use Greenlight\Core\Condition;

final class AlwaysCondition implements Condition
{
    #[\Override]
    public function isSatisfied(): bool
    {
        return true;
    }
}
