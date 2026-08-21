<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanExtensionConflict\ConflictingDigestExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new PluginDefinition(ConflictingDigestExtension::class, static fn(): ConflictingDigestExtension => new ConflictingDigestExtension()));
