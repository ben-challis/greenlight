<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\Plugins\FactoryIdentityChildPlugin;
use Greenlight\Tests\Fixture\Plugins\FactoryIdentityPlugin;

final class PluginFactoryTypeIdentityTest
{
    #[Test]
    #[DataRow(['case'])]
    #[DataRow(['alias'])]
    public function factoryReturnNamesResolveToTheirDeclaredClass(string $form): void
    {
        $declaredType = new \ReflectionClass(FactoryIdentityPlugin::class);
        $name = \strtolower($declaredType->name);

        if ($form === 'alias') {
            $name = __NAMESPACE__ . '\\FactoryIdentityAlias';
            \class_alias(FactoryIdentityPlugin::class, $name);
        }

        /** @var \Closure(): Plugin $factory */
        $factory = eval(\sprintf(
            'return static fn(): \\%s => new \\%s();',
            $name,
            FactoryIdentityPlugin::class,
        ));
        $definition = PluginDefinition::fromFactory($factory);

        Expect::that($definition->create())->toBeInstanceOf(FactoryIdentityPlugin::class);
        Expect::that($definition->pluginClass)->toBe(FactoryIdentityPlugin::class);
    }

    #[Test]
    public function selfReturnTypesUseTheFactoryClosureScope(): void
    {
        $definition = PluginDefinition::fromFactory(FactoryIdentityPlugin::scopedFactory());

        Expect::that($definition->pluginClass)->toBe(FactoryIdentityPlugin::class);
        Expect::that($definition->create())->toBeInstanceOf(FactoryIdentityPlugin::class);
    }

    #[Test]
    public function parentReturnTypesUseTheParentOfTheFactoryClosureScope(): void
    {
        $definition = PluginDefinition::fromFactory(FactoryIdentityChildPlugin::parentFactory());

        Expect::that($definition->pluginClass)->toBe(FactoryIdentityPlugin::class);
        Expect::that($definition->create())->toBeInstanceOf(FactoryIdentityPlugin::class);
    }
}
