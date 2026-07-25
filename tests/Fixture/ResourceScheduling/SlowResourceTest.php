<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\ResourceScheduling;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class SlowResourceTest
{
    #[Test]
    public function holdsTheResource(): void
    {
        \usleep(750_000);

        Expect::that(true)->toBeTrue();
    }
}
