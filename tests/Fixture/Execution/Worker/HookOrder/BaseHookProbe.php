<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker\HookOrder;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;

abstract class BaseHookProbe
{
    #[Before]
    public function baseFirstBefore(): void {}

    #[After]
    public function baseFirstAfter(): void {}

    #[Before]
    public function baseSecondBefore(): void {}

    #[After]
    public function baseSecondAfter(): void {}
}
