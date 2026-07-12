<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../CoverageSuite'])
    ->coverage(fn($c) => $c
        ->include(__DIR__ . '/../CoverageLib')
        ->include(__DIR__ . '/../../../src/Runner/Orchestrator')
        ->export('json', 'coverage-out/coverage.json'));
