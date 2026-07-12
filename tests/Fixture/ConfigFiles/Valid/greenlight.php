<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\SuiteBuilder;

return GreenlightConfig::create()
    ->paths(['tests/Unit', 'tests/Acceptance'])
    ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests/Unit'))
    ->suite('integration', static fn(SuiteBuilder $suite) => $suite->in('tests/Integration')->tag('io'))
    ->workers(count: 4, recycleAfterTests: 100, recycleAboveMemory: '128M')
    ->failFast(true)
    ->randomizeOrder(seed: 4242);
