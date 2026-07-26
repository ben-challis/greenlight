<?php

declare(strict_types=1);

use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\GreenlightConfig;

// This configuration adds coverage collection to the base configuration.
// The gate measures the same test set as the base suite.
$config = require __DIR__ . '/greenlight.php';
\assert($config instanceof GreenlightConfig);

return $config
    ->coverage(fn(CoverageBuilder $c) => $c
        ->include('src')
        ->driver('xdebug')
        ->export('json', 'build/coverage/coverage.json')
        ->export('cobertura', 'build/coverage/cobertura.xml'));
