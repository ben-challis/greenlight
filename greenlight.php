<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\StorageBuilder;

return GreenlightConfig::create()
    // Do not add tests/Fixture to these paths.
    // Some fixture tests intentionally call sleep(60) or exit(9), or retain memory.
    // Acceptance tests run these fixture suites in separate processes.
    ->paths(['tests/Unit', 'tests/Acceptance'])
    ->workers(count: 'auto')
    ->resourceLimit('analysis-process', 5)
    ->storage(static fn(StorageBuilder $storage) => $storage
        ->stateDirectory('build/greenlight-state'))
    ->randomizeOrder();
