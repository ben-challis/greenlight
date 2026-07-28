<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    // tests/Fixture MUST NOT be part of this suite.
    // Its Hang, Crash, and Leak suites call sleep(60) or exit(9).
    // Only acceptance tests can safely run these suites as subprocesses.
    ->paths(['tests/Unit', 'tests/Acceptance'])
    ->workers(count: 'auto')
    ->resourceLimit('analysis-process', 5)
    ->randomizeOrder();
