<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Tests\Fixture\Plugins\FakeCapabilityPlugin;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;
use Greenlight\Tests\Fixture\Plugins\PrioritizedFakeCapabilityPlugin;

final class PluginRegistryTest
{
    #[Test]
    public function emptyRegistryExposesNoCapabilitiesOrHarnessServices(): void
    {
        $registry = PluginRegistry::none();

        Expect::that($registry->testSubscribers())
            ->because('an empty registry MUST expose no plugin capabilities or harness services')
            ->toBe([])
            ->and($registry->retryDeciders())
            ->toBe([])
            ->and($registry->runSubscribers())
            ->toBe([])
            ->and($registry->harnessServices())
            ->toBe([])
            ->and($registry->serviceResolvers())
            ->toBe([]);
    }

    #[Test]
    public function capabilityAccessorsFilterPluginsAndKeepStablePriorityOrder(): void
    {
        $late = new PrioritizedFakeCapabilityPlugin(10);
        $prioritizedDefault = new PrioritizedFakeCapabilityPlugin(0);
        $unrelated = new NamedFakePlugin();
        $default = new FakeCapabilityPlugin();
        $early = new PrioritizedFakeCapabilityPlugin(-10);
        $registry = new PluginRegistry([$late, $prioritizedDefault, $unrelated, $default, $early]);
        $expected = [$early, $prioritizedDefault, $default, $late];

        Expect::that($registry->testSubscribers())
            ->because('capability accessors filter plugins and keep stable priority order')
            ->toBe($expected)
            ->and($registry->retryDeciders())->toBe($expected)
            ->and($registry->runSubscribers())->toBe($expected)
            ->and($registry->serviceResolvers())->toBe($expected)
            ->and($registry->ofType(NamedFakePlugin::class))->toBe([$unrelated]);
    }
}
