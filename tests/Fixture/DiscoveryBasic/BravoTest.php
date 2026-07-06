<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryBasic;

use Greenlight\Attribute\Test;

final class BravoTest
{
    #[Test]
    public function zulu(): void
    {
        echo "bravo:zulu\n";
    }

    #[Test]
    public function alpha(): void
    {
        echo "bravo:alpha\n";
    }

    #[Test]
    public function mike(): void
    {
        echo "bravo:mike\n";
    }
}
