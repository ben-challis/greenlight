<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid;

use Greenlight\Attribute\Group;
use Greenlight\Attribute\Test;

final class WrongGroupTypeTest
{
    #[Test]
    #[Group([])]
    public function neverDiscovered(): void {}
}
