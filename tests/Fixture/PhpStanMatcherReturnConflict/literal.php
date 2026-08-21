<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\PhpStanMatcherReturnConflict\LiteralReturnExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new LiteralReturnExtension());
