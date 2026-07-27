<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->withSkip([
        // Empty test methods and hooks have a purpose in a test framework.
        RemoveEmptyClassMethodRector::class,
        // Fixtures contain deliberate patterns. Without this exclusion, Rector changes some of these patterns.
        __DIR__ . '/tests/Fixture',
        StringClassNameToClassConstantRector::class => [__DIR__ . '/src/Rector'],
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true);
