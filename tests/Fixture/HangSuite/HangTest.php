<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\HangSuite;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;

final class HangTest
{
    #[Test]
    #[Timeout(seconds: 0.1)]
    public function hangsWellPastItsBudget(): void
    {
        \sleep(60);
    }
}
