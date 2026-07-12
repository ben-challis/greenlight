<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryClassNameMismatch;

use Greenlight\Attribute\Test;

final class SomethingElseTest
{
    #[Test]
    public function unreachable(): void
    {
        echo "wrong-name\n";
    }
}
