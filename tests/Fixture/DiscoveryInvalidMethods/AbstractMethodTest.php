<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryInvalidMethods;

use Greenlight\Attribute\Test;

abstract class AbstractMethodTest
{
    #[Test]
    abstract public function invalid(): void;
}
