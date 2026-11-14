<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker\HookOrder;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;

final class ChildHookProbe extends BaseHookProbe
{
    #[Before]
    public function childBefore(): void {}

    #[After]
    public function childAfter(): void {}
}
