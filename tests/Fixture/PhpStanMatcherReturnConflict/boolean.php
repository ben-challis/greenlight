<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\PhpStanMatcherReturnConflict\BooleanReturnExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new BooleanReturnExtension());
