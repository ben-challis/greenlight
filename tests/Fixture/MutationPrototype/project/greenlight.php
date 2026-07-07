<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

require_once __DIR__ . '/src/Temperature.php';
require_once __DIR__ . '/tests/TemperatureTest.php';

return GreenlightConfig::create()
    ->paths([__DIR__ . '/tests'])
    ->workers(1);
