<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanScopedMatcher\ScopedMatcherExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new PluginDefinition(ScopedMatcherExtension::class, static fn(): ScopedMatcherExtension => new ScopedMatcherExtension()));
