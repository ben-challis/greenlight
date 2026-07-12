<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryBasic;

use Greenlight\Attribute\Group;
use Greenlight\Attribute\Test;

final class CharlieTest
{
    #[Test]
    #[Group('slow')]
    public function crawls(): void
    {
        echo "charlie:crawls\n";
    }
}
