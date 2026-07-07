<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths(['tests/Unit', 'tests/Acceptance'])
    ->workers(count: 'auto');
