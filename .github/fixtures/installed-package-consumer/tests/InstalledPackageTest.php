<?php

declare(strict_types=1);

namespace Greenlight\ConsumerSmoke;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;

final class InstalledPackageTest
{
    #[Test]
    public function installedPackageAutoloadsPublicClasses(): void
    {
        Expect::that(class_exists(GreenlightConfig::class))->toBeTrue();
    }
}
