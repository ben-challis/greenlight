<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Plugin\CoverageMapTransformer;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\Prioritized;

/**
 * Applies coverage-map plugins for one completed run.
 *
 * @internal
 */
final readonly class CoveragePluginRuntime
{
    /** @var list<CoverageMapTransformer> */
    private array $transformers;

    /**
     * @param list<PluginDefinition> $definitions
     * @throws CoverageError
     */
    public static function fromDefinitions(array $definitions): self
    {
        $plugins = [new IgnoreCoverage()];

        foreach ($definitions as $definition) {
            if (!$definition->supports(CoverageMapTransformer::class)) {
                continue;
            }

            try {
                $plugins[] = $definition->create();
            } catch (\Throwable $failure) {
                throw CoverageError::pluginFailed($definition->pluginClass, 'creation', $failure);
            }
        }

        return new self($plugins);
    }

    /** @param list<Plugin> $plugins */
    public static function fromPlugins(array $plugins): self
    {
        return new self([new IgnoreCoverage(), ...$plugins]);
    }

    /** @param list<Plugin> $plugins */
    private function __construct(array $plugins)
    {
        $indexed = [];

        foreach ($plugins as $registration => $plugin) {
            if (!$plugin instanceof CoverageMapTransformer) {
                continue;
            }

            $indexed[] = [
                'transformer' => $plugin,
                'priority' => $plugin instanceof Prioritized ? $plugin->priority() : 0,
                'registration' => $registration,
            ];
        }

        \usort(
            $indexed,
            static fn(array $a, array $b): int => [$a['priority'], $a['registration']]
                <=> [$b['priority'], $b['registration']],
        );

        $transformers = [];

        foreach ($indexed as $entry) {
            $transformers[] = $entry['transformer'];
        }

        $this->transformers = $transformers;
    }

    /** @throws CoverageError */
    public function transform(CoverageMap $coverage): CoverageMap
    {
        foreach ($this->transformers as $transformer) {
            try {
                $coverage = $transformer->transformCoverageMap($coverage);
            } catch (\Throwable $failure) {
                throw CoverageError::pluginFailed($transformer::class, 'transformCoverageMap', $failure);
            }
        }

        return $coverage;
    }
}
