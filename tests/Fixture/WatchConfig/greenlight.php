<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../WatchSuite'])
    ->workers(count: 1)
    ->watch(fn($w) => $w->debounceMilliseconds(50));
