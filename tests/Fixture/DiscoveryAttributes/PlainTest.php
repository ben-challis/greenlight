<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryAttributes;

use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;

final class PlainTest
{
    #[Test]
    public function bare(): void
    {
        echo "plain:bare\n";
    }

    #[Test]
    #[Group('only-here')]
    #[Skip('not today')]
    #[SkipUnless(AlwaysTrue::class)]
    #[Retry(3, onlyOn: \LogicException::class)]
    #[Timeout(2.5)]
    #[Isolated]
    public function fullyDecorated(): void
    {
        echo "plain:decorated\n";
    }
}
