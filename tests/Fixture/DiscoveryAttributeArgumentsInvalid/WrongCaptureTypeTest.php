<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid;

use Greenlight\Attribute\Test;

final class WrongCaptureTypeTest
{
    #[Test(capture: [])]
    public function neverDiscovered(): void {}
}
