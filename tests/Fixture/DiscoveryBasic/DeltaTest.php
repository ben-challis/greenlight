<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryBasic;

use Greenlight\Attribute\Test;

final class DeltaTest
{
    #[Test]
    public function flies(): void
    {
        echo "delta:flies\n";
    }
}
