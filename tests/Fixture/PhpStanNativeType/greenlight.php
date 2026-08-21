<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanNativeType\NativeTypeExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new PluginDefinition(NativeTypeExtension::class, static fn(): NativeTypeExtension => new NativeTypeExtension()));
