<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryInvalidMethods;

use Greenlight\Attribute\Test;

final class StaticMethodTest
{
    #[Test]
    public static function invalid(): void {}
}
