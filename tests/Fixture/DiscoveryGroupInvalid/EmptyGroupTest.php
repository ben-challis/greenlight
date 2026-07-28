<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryGroupInvalid;

use Greenlight\Attribute\Group;
use Greenlight\Attribute\Test;

final class EmptyGroupTest
{
    #[Test]
    #[Group('')]
    public function neverDiscovered(): void {}
}
