<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\ResourceScheduling;

use Greenlight\Attribute\Test;


use function Greenlight\expect;

final class WaitingResourceTest
{
    #[Test]
    public function runsAfterTheWait(): void
    {
        expect(true)->toBeTrue();
    }
}
