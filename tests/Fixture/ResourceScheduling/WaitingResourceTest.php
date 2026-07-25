<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\ResourceScheduling;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class WaitingResourceTest
{
    #[Test]
    public function runsAfterTheWait(): void
    {
        Expect::that(true)->toBeTrue();
    }
}
