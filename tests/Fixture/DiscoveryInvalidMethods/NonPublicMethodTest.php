<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryInvalidMethods;

use Greenlight\Attribute\Test;

final class NonPublicMethodTest
{
    #[Test]
    private function invalid(): void {}
}
