<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;

final readonly class PluginDefinitionTest
{
    #[Test]
    public function factoryCreatesFreshInstancesOfTheDeclaredPluginClass(): void
    {
        $definition = PluginDefinition::fromFactory(
            static fn(): NamedFakePlugin => new NamedFakePlugin(),
        );

        $first = $definition->create();
        $second = $definition->create();

        Expect::that($first)->toBeInstanceOf(NamedFakePlugin::class);
        Expect::that($second)
            ->because('a plugin definition factory MUST create a fresh instance on each call')
            ->not()
            ->toBe($first);
    }

    #[Test]
    public function factoryRequiresAPluginReturnType(): void
    {
        Expect::that(static fn(): PluginDefinition => PluginDefinition::fromFactory(
            static fn(): \stdClass => new \stdClass(), // @phpstan-ignore argument.type (This test supplies an invalid plugin factory.)
        ))
            ->because('a plugin factory return type MUST name a plugin class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Plugin factory return type "stdClass" must implement Greenlight\Plugin\Plugin.',
            );
    }

    #[Test]
    public function factoryRequiresADeclaredReturnType(): void
    {
        Expect::that(static fn(): PluginDefinition => PluginDefinition::fromFactory(
            static fn() => new QuarantinePlugin(),
        ))
            ->because('a plugin factory MUST declare its plugin class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'A plugin factory must declare one non-null concrete plugin class return type.',
            );
    }

    #[Test]
    public function factoryRequiresAConcreteReturnType(): void
    {
        Expect::that(static fn(): PluginDefinition => PluginDefinition::fromFactory(
            static fn(): AbstractPlugin => throw new \LogicException('The factory must not run.'),
        ))
            ->because('a plugin factory MUST declare a concrete plugin class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Plugin factory return type "Greenlight\Tests\Unit\Plugin\AbstractPlugin" must be a concrete class.',
            );
    }

    #[Test]
    public function factoryMustReturnExactlyItsDeclaredPluginClass(): void
    {
        $definition = PluginDefinition::fromFactory(
            static fn(): BasePlugin => new ChildPlugin(),
        );

        Expect::that($definition->create(...))
            ->because('the declared class MUST describe all capabilities of the returned plugin')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'The factory for plugin "Greenlight\Tests\Unit\Plugin\BasePlugin" returned Greenlight\Tests\Unit\Plugin\ChildPlugin.',
            );
    }
}

abstract class AbstractPlugin implements Plugin {}

class BasePlugin implements Plugin {}

final class ChildPlugin extends BasePlugin {}
