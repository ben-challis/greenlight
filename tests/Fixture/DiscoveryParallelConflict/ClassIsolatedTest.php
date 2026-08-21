<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryParallelConflict;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;

#[AllowParallel]
#[Isolated]
final readonly class ClassIsolatedTest
{
    #[Test]
    public function neverRuns(): void {}
}
