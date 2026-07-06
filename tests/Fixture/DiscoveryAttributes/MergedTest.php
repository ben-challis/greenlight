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

#[Group('cls-a')]
#[Group('cls-b')]
#[Skip('class-wide skip')]
#[SkipUnless(AlwaysTrue::class)]
#[Retry(2)]
#[Timeout(30.0)]
#[Isolated]
final class MergedTest
{
    #[Test]
    public function inheritsClassLevel(): void
    {
        echo "merged:inherits\n";
    }

    #[Test]
    #[Group('m')]
    #[Group('cls-a')]
    #[Skip('method skip')]
    #[SkipUnless(AlwaysFalse::class)]
    #[Retry(5, onlyOn: \RuntimeException::class)]
    #[Timeout(1.5)]
    public function overridesClassLevel(): void
    {
        echo "merged:overrides\n";
    }
}
