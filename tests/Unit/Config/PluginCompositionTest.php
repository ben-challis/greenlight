<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;

use function Greenlight\expect;

final readonly class PluginCompositionTest
{
    #[Test]
    public function repeatedPluginCallsAppendInConfigurationOrder(): void
    {
        $first = static fn(): NamedFakePlugin => new NamedFakePlugin();
        $second = static fn(): NamedFakePlugin => new NamedFakePlugin();

        $plugins = GreenlightConfig::create()
            ->plugins($first)
            ->plugins($second)
            ->build()
            ->execution
            ->plugins;

        expect($plugins)
            ->because('repeated plugin calls MUST retain every configured plugin in order')
            ->toHaveCount(2);
        expect($plugins[0]->pluginClass)->toBe(NamedFakePlugin::class);
        expect($plugins[1]->pluginClass)->toBe(NamedFakePlugin::class);
    }

    #[Test]
    public function pluginFactoryReturnTypesCreateDefinitionsWithoutCallingFactories(): void
    {
        $constructions = 0;

        $plugins = GreenlightConfig::create()
            ->plugins(static function () use (&$constructions): NamedFakePlugin {
                ++$constructions;

                return new NamedFakePlugin();
            })
            ->build()
            ->execution
            ->plugins;

        expect($plugins[0]->pluginClass)
            ->because('Greenlight MUST get the plugin class without calling its factory')
            ->toBe(NamedFakePlugin::class);
        expect($constructions)->toBe(0);
    }

    #[Test]
    public function rejectedPluginFactoriesDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()
            ->plugins(static fn(): NamedFakePlugin => new NamedFakePlugin());

        expect()->calling(static fn(): GreenlightConfig => $builder->plugins(
            static fn(): NamedFakePlugin => new NamedFakePlugin(),
            static fn() => new NamedFakePlugin(),
        ))
            ->because('each plugin factory MUST declare its concrete plugin class')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'A plugin factory must declare one non-null concrete plugin class return type.',
            );

        expect($builder->build()->execution->plugins)
            ->because('a rejected plugin call MUST not append its earlier valid factories')
            ->toHaveCount(1);
    }
}
