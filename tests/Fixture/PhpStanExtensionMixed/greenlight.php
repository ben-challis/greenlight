<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;
use Greenlight\Tests\Fixture\Plugins\FakeCapabilityPlugin;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(
        new PluginDefinition(FakeCapabilityPlugin::class, static fn(): FakeCapabilityPlugin => new FakeCapabilityPlugin()),
        new PluginDefinition(DigestExtension::class, static fn(): DigestExtension => new DigestExtension()),
    );
