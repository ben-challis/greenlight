<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\PhpStanMatcherReturnConflict\LiteralReturnExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new PluginDefinition(LiteralReturnExtension::class, static fn(): LiteralReturnExtension => new LiteralReturnExtension()));
