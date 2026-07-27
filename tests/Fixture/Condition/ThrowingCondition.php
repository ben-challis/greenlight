<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Condition;

use Greenlight\Core\Condition;
use Greenlight\Doubles\Fake;

final class ThrowingCondition implements Condition, Fake
{
    #[\Override]
    public function isSatisfied(): bool
    {
        throw new \RuntimeException('condition evaluation failed');
    }
}
