<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\StorageBuilder;

return GreenlightConfig::create()
    ->paths(['tests/Unit', 'tests/Acceptance'])
    ->workers(count: 'auto')
    ->resourceLimit('analysis-process', 5)
    ->storage(static fn(StorageBuilder $storage) => $storage
        ->stateDirectory('build/greenlight-state'))
    ->randomizeOrder();
