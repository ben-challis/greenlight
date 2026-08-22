<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;
use Greenlight\Tests\Fixture\Plugins\FakeCapabilityPlugin;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(
        static fn(): FakeCapabilityPlugin => new FakeCapabilityPlugin(),
        static fn(): DigestExtension => new DigestExtension(),
    );
