<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Configuration\RectorConfigBuilder;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

/**
 * Creates the shared Rector policy for one set of paths.
 *
 * @param non-empty-list<string> $paths
 * @param list<class-string> $additionalSkips
 */
function greenlightRectorConfig(
    array $paths,
    string $cacheDirectory,
    array $additionalSkips = [],
): RectorConfigBuilder {
    return RectorConfig::configure()
        ->withPaths($paths)
        ->withCache(
            cacheDirectory: $cacheDirectory,
            cacheClass: FileCacheStorage::class,
        )
        ->withSkip([
            // Empty test methods and hooks have a purpose in a test framework.
            RemoveEmptyClassMethodRector::class,
            ...$additionalSkips,
            // Fixtures contain deliberate patterns. Without this exclusion, Rector changes some of these patterns.
            __DIR__ . '/../tests/Fixture',
            StringClassNameToClassConstantRector::class => [__DIR__ . '/../src/Rector'],
        ])
        ->withPhpSets(php84: true)
        ->withPreparedSets(deadCode: true, codeQuality: true);
}
