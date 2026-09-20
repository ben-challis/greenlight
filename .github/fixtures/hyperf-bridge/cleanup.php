<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/tests/Cleanup'])
    ->workers(1);
