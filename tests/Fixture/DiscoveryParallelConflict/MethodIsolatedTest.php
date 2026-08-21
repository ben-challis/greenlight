<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryParallelConflict;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;

#[AllowParallel]
final readonly class MethodIsolatedTest
{
    #[Test]
    #[Isolated]
    public function neverRuns(): void {}
}
