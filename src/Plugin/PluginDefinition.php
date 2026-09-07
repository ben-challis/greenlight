<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Defines how Greenlight creates one type of plugin.
 *
 * @internal
 */
final readonly class PluginDefinition
{
    /**
     * @param class-string<Plugin> $pluginClass
     * @param \Closure(): Plugin $factory Return a new plugin
     *   instance on each call.
     */
    private function __construct(
        public string $pluginClass,
        private \Closure $factory,
    ) {}

    /**
     * @param \Closure(): Plugin $factory
     */
    public static function fromFactory(\Closure $factory): self
    {
        $reflection = new \ReflectionFunction($factory);
        $returnType = $reflection->getReturnType();

        if (!$returnType instanceof \ReflectionNamedType
            || $returnType->isBuiltin()
            || $returnType->allowsNull()
        ) {
            throw new \InvalidArgumentException(
                'A plugin factory must declare one non-null concrete plugin class return type.',
            );
        }

        $pluginClass = $returnType->getName();

        if ($pluginClass === 'self') {
            $pluginClass = $reflection->getClosureScopeClass()->name ?? $pluginClass;
        } elseif ($pluginClass === 'parent') {
            $parent = $reflection->getClosureScopeClass()?->getParentClass();
            $pluginClass = $parent instanceof \ReflectionClass ? $parent->name : $pluginClass;
        }

        $pluginType = \class_exists($pluginClass) ? new \ReflectionClass($pluginClass) : null;

        if (!$pluginType instanceof \ReflectionClass || $pluginType->isAbstract()) {
            throw new \InvalidArgumentException(\sprintf(
                'Plugin factory return type "%s" must be a concrete class.',
                $pluginClass,
            ));
        }

        $pluginClass = $pluginType->name;

        if (!\is_a($pluginClass, Plugin::class, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Plugin factory return type "%s" must implement %s.',
                $pluginClass,
                Plugin::class,
            ));
        }

        /** @var class-string<Plugin> $pluginClass */
        return new self($pluginClass, $factory);
    }

    public function create(): Plugin
    {
        $plugin = ($this->factory)();

        if ($plugin::class !== $this->pluginClass) {
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
