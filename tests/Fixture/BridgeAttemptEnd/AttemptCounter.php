<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BridgeAttemptEnd;

use Greenlight\Doubles\Fake;

final class AttemptCounter implements Fake
{
    public int $value = 0;
}
