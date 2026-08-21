<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** Defines how Greenlight creates one type of plugin. */
final readonly class PluginDefinition
{
    /**
     * @param class-string<Plugin> $pluginClass
     * @param \Closure(): Plugin $factory The factory MUST return a new plugin
     *   instance on each call.
     */
    public function __construct(
        public string $pluginClass,
        private \Closure $factory,
    ) {
        if (!\is_a($pluginClass, Plugin::class, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Plugin class "%s" must implement %s.',
                $pluginClass,
                Plugin::class,
            ));
        }
    }

    public function create(): Plugin
    {
        $plugin = ($this->factory)();

        if (!$plugin instanceof $this->pluginClass) {
            throw new \InvalidArgumentException(\sprintf(
                'The factory for plugin "%s" returned %s.',
                $this->pluginClass,
                \get_debug_type($plugin),
            ));
        }

        return $plugin;
    }

    /**
     * @param class-string $capability
     */
    public function supports(string $capability): bool
    {
        return \is_a($this->pluginClass, $capability, true);
    }
}
