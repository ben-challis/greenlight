<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../PluginRunSuite'])
    ->plugins(new QuarantinePlugin());
