<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;

final class WrongResourceTypeTest
{
    #[Test]
    #[RequiresResource([])]
    public function neverDiscovered(): void {}
}
