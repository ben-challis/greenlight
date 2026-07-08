<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\Configuration;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\MemorySize;

/**
 * Renders a resolved configuration as the human-readable plan the run
 * command prints before execution exists.
 *
 * @internal
 */
final class PlanFormatter
{
    private function __construct() {}

    public static function format(Configuration $configuration, string $configFile): string
    {
        $lines = [];
        $lines[] = 'Run plan';
        $lines[] = '  config file: ' . $configFile;
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

        if (!$configuration->coverage instanceof CoverageConfiguration) {
            $lines[] = '  coverage: (off)';
        } else {
            $exports = [];

            foreach ($configuration->coverage->exports as $export) {
                $exports[] = $export->format . ' -> ' . $export->target;
            }

            $lines[] = \sprintf(
                '  coverage: include %s; driver %s; exports %s',
                $configuration->coverage->includePaths === [] ? '(nothing)' : \implode(', ', $configuration->coverage->includePaths),
                $configuration->coverage->driver ?? '(auto)',
                $exports === [] ? '(none)' : \implode(', ', $exports),
            );
        }

        return \implode("\n", $lines) . "\n";
    }
}
