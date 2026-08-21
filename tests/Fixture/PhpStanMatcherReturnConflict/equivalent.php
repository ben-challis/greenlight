<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanMatcherReturnConflict\EquivalentBooleanReturnExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new PluginDefinition(EquivalentBooleanReturnExtension::class, static fn(): EquivalentBooleanReturnExtension => new EquivalentBooleanReturnExtension()));
