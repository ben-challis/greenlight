<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryBasic;

use Greenlight\Attribute\Test;

abstract class AbstractSharedTest
{
    #[Test]
    public function neverPlanned(): void
    {
        echo "abstract:never\n";
    }
}
