<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\StorageBuilder;

$config = GreenlightConfig::create()
    ->paths(['tests/Unit', 'tests/Acceptance'])
    ->workers(count: 'auto')
    ->resourceLimit('analysis-process', 5)
    ->storage(static fn(StorageBuilder $storage) => $storage
        ->stateDirectory('build/greenlight-state'));

if (\getenv('GREENLIGHT_CI_USE_RUN_STATE') !== '1') {
    $config->randomizeOrder();
}

return $config;
