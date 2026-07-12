<?php

declare(strict_types=1);

use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\GreenlightConfig;

// Layers coverage collection on the base config so the gate always measures
// the same test set the real suite runs.
$config = require __DIR__ . '/greenlight.php';
\assert($config instanceof GreenlightConfig);

return $config
    ->coverage(fn(CoverageBuilder $c) => $c
        ->include('src')
        ->driver('xdebug')
        ->export('json', 'build/coverage/coverage.json'));
