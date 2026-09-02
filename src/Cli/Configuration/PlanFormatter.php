<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\StorageLayout;

/**
 * Formats resolved run settings for dry-run output.
 *
 * @internal
 */
final class PlanFormatter
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function format(
        ResolvedConfiguration $configuration,
        string $configFile,
        string $workingDirectory,
    ): string {
        $lines = [];
        $lines[] = 'Run plan';
        $lines[] = '  configuration file: ' . $configFile;
        $suiteSelection = $configuration->suiteSelection;
        $lines[] = '  test paths: ' . ($suiteSelection->explicit
            ? '(excluded by suite selection)'
            : \implode(', ', $configuration->discovery->paths));

        if ($suiteSelection->explicit) {
            $lines[] = '  suite names: ' . ($suiteSelection->names === [] ? '(none)' : \implode(', ', $suiteSelection->names));
            $lines[] = '  suite tags: ' . ($suiteSelection->tags === [] ? '(none)' : \implode(', ', $suiteSelection->tags));
        }

        if ($suiteSelection->suites === []) {
            $lines[] = '  suites: (none)';
        } else {
            foreach ($suiteSelection->suites as $suite) {
                $tags = $suite->tags === [] ? '' : ' [tags: ' . \implode(', ', $suite->tags) . ']';
                $lines[] = \sprintf('  suite %s: %s%s', $suite->name, \implode(', ', $suite->paths), $tags);
            }
        }

        $lines[] = '  workers: ' . $configuration->workers->count->describe();
        $resourceLimits = [];

        foreach ($configuration->workers->resourceLimits as $name => $limit) {
            $resourceLimits[] = $name . '=' . $limit;
        }

        $lines[] = '  resource limits: ' . ($resourceLimits === [] ? '(default 1 per required resource)' : \implode(', ', $resourceLimits));
        $lines[] = '  stop after: ' . match (true) {
            $configuration->execution->stopAfterFailures === null => 'never',
            $configuration->execution->stopAfterFailures === 1 => '1 failed or errored test',
            default => $configuration->execution->stopAfterFailures . ' failed or errored tests',
        };

        $seed = $configuration->order->seed;

        if ($seed === null) {
            $lines[] = '  order: declared';
        } else {
            $lines[] = \sprintf('  order: random (seed %d)', $seed);
        }

        $lines[] = '  groups: ' . ($configuration->selection->include->groups === [] ? '(all)' : \implode(', ', $configuration->selection->include->groups));

        $plugins = [];

        foreach ($configuration->execution->plugins as $plugin) {
            $plugins[] = $plugin->pluginClass;
        }

        $lines[] = '  plugins: ' . ($plugins === [] ? '(none)' : \implode(', ', $plugins));
        $lines[] = '  artifacts: ' . $configuration->execution->artifacts->directory;
        $storage = StorageLayout::resolve(
            $configuration->storage,
            $workingDirectory,
            $suiteSelection->stateIdentity(),
        );
        $lines[] = '  storage state: ' . $storage->runStateFile;
        $lines[] = '  storage cache: ' . $storage->cacheDirectory;
        $lines[] = '  storage generated code: ' . $storage->generatedCodeDirectory;
        $lines[] = '  storage temporary: ' . $storage->temporaryDirectory;
        $lines[] = '  watch debounce: ' . $configuration->watch->debounceMilliseconds . ' ms';
        $lines[] = '  additional watch paths: ' . ($configuration->watch->paths === [] ? '(none)' : \implode(', ', $configuration->watch->paths));
        $lines[] = '  watch include patterns: ' . ($configuration->watch->includePatterns === [] ? '(all additional directory files)' : \implode(', ', $configuration->watch->includePatterns));
        $lines[] = '  watch exclude patterns: ' . ($configuration->watch->excludePatterns === [] ? '(none)' : \implode(', ', $configuration->watch->excludePatterns));
        $lines[] = '  watch file limit: ' . $configuration->watch->maximumFiles;

        if (!$configuration->coverage instanceof CoverageConfiguration) {
            $lines[] = '  coverage: (off)';
        } else {
            $exports = [];

            foreach ($configuration->coverage->exports as $export) {
                $exports[] = $export->format . ' -> ' . $export->target;
            }

            $lines[] = '  coverage include paths: ' . ($configuration->coverage->includePaths === [] ? '(none)' : \implode(', ', $configuration->coverage->includePaths));
            $lines[] = '  coverage driver: ' . ($configuration->coverage->driver ?? '(auto)');
            $lines[] = '  coverage driver required: ' . ($configuration->coverage->requireDriver ? 'yes' : 'no');
            $lines[] = '  minimum coverage: ' . ($configuration->coverage->minimumPercentage === null ? '(none)' : \sprintf('%.2f%%', $configuration->coverage->minimumPercentage));
            $lines[] = '  maximum uncovered lines: ' . ($configuration->coverage->maximumUncoveredLines === null ? '(none)' : (string) $configuration->coverage->maximumUncoveredLines);
            $lines[] = '  coverage exports: ' . ($exports === [] ? '(none)' : \implode(', ', $exports));
        }

        return \implode("\n", $lines) . "\n";
    }
}
