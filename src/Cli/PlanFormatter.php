<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\Configuration;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\MemorySize;

/** @internal */
final class PlanFormatter
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function format(Configuration $configuration, string $configFile): string
    {
        $lines = [];
        $lines[] = 'Run plan';
        $lines[] = '  configuration file: ' . $configFile;
        $lines[] = '  test paths: ' . \implode(', ', $configuration->paths);

        if ($configuration->suites === []) {
            $lines[] = '  suites: (none)';
        } else {
            foreach ($configuration->suites as $suite) {
                $tags = $suite->tags === [] ? '' : ' [tags: ' . \implode(', ', $suite->tags) . ']';
                $lines[] = \sprintf('  suite %s: %s%s', $suite->name, \implode(', ', $suite->paths), $tags);
            }
        }

        $lines[] = '  workers: ' . $configuration->workers->describe();
        $resourceLimits = [];

        foreach ($configuration->resourceLimits as $name => $limit) {
            $resourceLimits[] = $name . '=' . $limit;
        }

        $lines[] = '  resource limits: ' . ($resourceLimits === [] ? '(default 1 per required resource)' : \implode(', ', $resourceLimits));
        $lines[] = $configuration->recycleAfterTests === null
            ? \sprintf('  recycle: above %s memory', MemorySize::format($configuration->recycleAboveMemoryBytes))
            : \sprintf(
                '  recycle: after %d tests or above %s memory',
                $configuration->recycleAfterTests,
                MemorySize::format($configuration->recycleAboveMemoryBytes),
            );

        $lines[] = '  stop after: ' . match (true) {
            $configuration->stopAfterFailures === null => 'never',
            $configuration->stopAfterFailures === 1 => '1 failure',
            default => $configuration->stopAfterFailures . ' failures',
        };

        if (!$configuration->randomizeOrder) {
            $lines[] = '  order: declared';
        } elseif ($configuration->randomSeed !== null) {
            $lines[] = \sprintf('  order: random (seed %d)', $configuration->randomSeed);
        } else {
            $lines[] = '  order: random (seed chosen at run time)';
        }

        $lines[] = '  groups: ' . ($configuration->groups === [] ? '(all)' : \implode(', ', $configuration->groups));

        $plugins = [];

        foreach ($configuration->plugins as $plugin) {
            $plugins[] = $plugin::class;
        }

        $lines[] = '  plugins: ' . ($plugins === [] ? '(none)' : \implode(', ', $plugins));
        $lines[] = '  artifacts: ' . $configuration->artifacts->directory;

        if (!$configuration->coverage instanceof CoverageConfiguration) {
            $lines[] = '  coverage: (off)';
        } else {
            $exports = [];

            foreach ($configuration->coverage->exports as $export) {
                $exports[] = $export->format . ' -> ' . $export->target;
            }

            $lines[] = '  coverage include paths: ' . ($configuration->coverage->includePaths === [] ? '(none)' : \implode(', ', $configuration->coverage->includePaths));
            $lines[] = '  coverage driver: ' . ($configuration->coverage->driver ?? '(auto)');
            $lines[] = '  coverage exports: ' . ($exports === [] ? '(none)' : \implode(', ', $exports));
        }

        return \implode("\n", $lines) . "\n";
    }
}
