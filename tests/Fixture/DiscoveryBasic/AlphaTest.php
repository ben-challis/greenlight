<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryBasic;

use Greenlight\Attribute\Group;
use Greenlight\Attribute\Test;

#[Group('basic')]
final class AlphaTest
{
    #[Test]
    public function one(): void
    {
        echo "alpha:one\n";
    }

    #[Test]
    #[Group('slow')]
    public function two(): void
    {
        echo "alpha:two\n";
    }

    public function notATest(): void
    {
        echo "alpha:not-a-test\n";
    }
}
