<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryResourceInvalid;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;

final class InvalidResourceTest
{
    #[Test]
    #[RequiresResource('not valid')]
    public function neverDiscovered(): void {}
}
