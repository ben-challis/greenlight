<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/../CoverageIgnoreSuite'])
    ->coverage(fn($c) => $c
        ->include(__DIR__ . '/../CoverageIgnoreLib')
        ->export('json', 'coverage-out/coverage.json'));
