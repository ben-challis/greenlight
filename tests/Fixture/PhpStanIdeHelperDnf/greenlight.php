<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\PhpStanIdeHelperDnf\DnfExtension;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../DiscoveryBasic'])
    ->plugins(new DnfExtension());
