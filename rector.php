<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->withSkip([
        // Empty test methods and hooks have a purpose in a test framework.
        RemoveEmptyClassMethodRector::class,
        // Fixtures contain deliberate patterns. Without this exclusion, Rector changes some of these patterns.
        __DIR__ . '/tests/Fixture',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true);
