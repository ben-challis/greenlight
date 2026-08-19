<?php

declare(strict_types=1);

use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\GreenlightConfig;

$config = require __DIR__ . '/greenlight.php';
\assert($config instanceof GreenlightConfig);

return $config
    ->coverage(fn(CoverageBuilder $coverage) => $coverage
        ->include('src/Tempest')
        ->driver('xdebug')
        ->export('json', 'build/coverage/coverage.json')
        ->export('cobertura', 'build/coverage/cobertura.xml'));
