<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->withSkip([
        // Empty test methods and hooks are meaningful in a testing framework.
        RemoveEmptyClassMethodRector::class,
        // Fixtures encode deliberate patterns, including ones rector would "fix".
        __DIR__ . '/tests/Fixture',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true);
