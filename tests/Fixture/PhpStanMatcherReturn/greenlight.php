<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanMatcherReturn\InvalidReturnExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new PluginDefinition(InvalidReturnExtension::class, static fn(): InvalidReturnExtension => new InvalidReturnExtension()));
