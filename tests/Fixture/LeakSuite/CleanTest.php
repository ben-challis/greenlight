<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\LeakSuite;

use Greenlight\Attribute\Test;

final class CleanTest
{
    #[Test]
    public function passesAndIsCollectable(): void {}
}
