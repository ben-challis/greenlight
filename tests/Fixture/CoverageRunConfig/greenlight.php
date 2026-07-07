<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../CoverageSuite'])
    ->coverage(fn($c) => $c
        ->include(__DIR__ . '/../CoverageLib')
        ->export('json', 'coverage-out/coverage.json')
        ->export('lcov', 'coverage-out/lcov.info'));
