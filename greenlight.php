<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    // tests/Fixture must never be included here: its Hang/Crash/Leak suites
    // call sleep(60)/exit(9) and are only safe when driven as subprocesses
    // by acceptance tests.
    ->paths(['tests/Unit', 'tests/Acceptance'])
    ->workers(count: 'auto')
    ->randomizeOrder();
