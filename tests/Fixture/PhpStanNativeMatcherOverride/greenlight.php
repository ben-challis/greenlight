<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride\NativeMatcherOverrideExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(static fn(): NativeMatcherOverrideExtension => new NativeMatcherOverrideExtension());
