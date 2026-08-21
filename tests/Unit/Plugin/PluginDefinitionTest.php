<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;

final readonly class PluginDefinitionTest
{
    #[Test]
    public function factoryCreatesFreshInstancesOfTheDeclaredPluginClass(): void
    {
        $definition = new PluginDefinition(
            NamedFakePlugin::class,
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
    public function constructorRejectsAClassThatIsNotAPlugin(): void
    {
        Expect::that(static fn(): PluginDefinition => new PluginDefinition(
            \stdClass::class, // @phpstan-ignore argument.type (This test supplies an invalid plugin class.)
            static fn(): NamedFakePlugin => new NamedFakePlugin(),
        ))
            ->because('a plugin definition MUST name a plugin class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Plugin class "stdClass" must implement Greenlight\Plugin\Plugin.',
            );
    }

    #[Test]
    public function factoryMustReturnTheDeclaredPluginClass(): void
    {
        $definition = new PluginDefinition(
            NamedFakePlugin::class,
            static fn(): QuarantinePlugin => new QuarantinePlugin(),
        );

        Expect::that($definition->create(...))
            ->because('a plugin factory MUST return the class that its definition names')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'The factory for plugin "Greenlight\Tests\Fixture\Plugins\NamedFakePlugin" returned Greenlight\Tests\Fixture\Plugins\QuarantinePlugin.',
            );
    }
}
