<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\AfterFails;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Test;

final class AfterFailsTest
{
    #[Test]
    public function passesUntilTeardown(): void {}

    #[After]
    public function breaks(): never
    {
        throw new \RuntimeException('after broke');
    }
}
