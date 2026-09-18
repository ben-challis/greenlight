<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\ResourceScheduling;

use Greenlight\Attribute\Test;


use function Greenlight\expect;

final class SlowResourceTest
{
    #[Test]
    public function holdsTheResource(): void
    {
        \usleep(750_000);

        expect(true)->toBeTrue();
    }
}
