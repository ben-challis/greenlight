<?php

declare(strict_types=1);

namespace Greenlight\ConsumerSmoke;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;

use function Greenlight\expect;

final class InstalledPackageTest
{
    #[Test]
    public function installedPackageAutoloadsPublicClasses(): void
    {
        expect(\class_exists(GreenlightConfig::class))->toBeTrue();
    }
}
