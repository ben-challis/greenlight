<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\Prioritized;

/**
 * Selects owner-local plugin instances and orders their capabilities.
 *
 * @internal
 */
abstract readonly class PluginRuntime
{
    /**
     * @var list<array{plugin: Plugin, priority: int, registration: int}>
     */
    private array $plugins;

    /** @var list<Plugin> */
    private array $orderedPlugins;

    /**
     * @param list<Plugin> $plugins
     */
    protected function __construct(array $plugins)
    {
        $indexed = [];

        foreach ($plugins as $registration => $plugin) {
            $indexed[] = [
                'plugin' => $plugin,
                'priority' => $plugin instanceof Prioritized ? $plugin->priority() : 0,
                'registration' => $registration,
            ];
        }

        $this->plugins = $indexed;

        \usort(
            $indexed,
            static fn(array $a, array $b): int => [$a['priority'], $a['registration']]
                <=> [$b['priority'], $b['registration']],
        );
        $this->orderedPlugins = \array_column($indexed, 'plugin');
    }

    /**
     * @param list<PluginDefinition> $definitions
     * @param non-empty-list<class-string> $capabilities
     *
     * @return list<Plugin>
     */
    final protected static function createOwned(array $definitions, array $capabilities): array
    {
        $plugins = [];

        foreach ($definitions as $definition) {
            foreach ($capabilities as $capability) {
                if ($definition->supports($capability)) {
                    $plugins[] = $definition->create();

                    break;
                }
            }
        }

        return $plugins;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $capability
     *
     * @return list<Plugin&T>
     */
    final protected function matching(string $capability): array
    {
        $matching = [];

        foreach ($this->plugins as $entry) {
            if ($entry['plugin'] instanceof $capability) {
                $matching[] = $entry['plugin'];
            }
        }

        return $matching;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $capability
     *
     * @return list<Plugin&T>
     */
    final protected function ordered(string $capability): array
    {
        return \array_values(\array_filter(
            $this->orderedPlugins,
            static fn(Plugin $plugin): bool => $plugin instanceof $capability,
        ));
    }
}
