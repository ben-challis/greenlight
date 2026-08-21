<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new PluginDefinition(DigestExtension::class, static fn(): DigestExtension => new DigestExtension()));
