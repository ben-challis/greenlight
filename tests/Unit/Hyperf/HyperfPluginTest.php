<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Service;
use Greenlight\Hyperf\HyperfBridgeError;
use Greenlight\Hyperf\HyperfPlugin;
use Greenlight\Tests\Support\Psr11\ArrayContainer;

final readonly class HyperfPluginTest
{
    #[Test]
    public function exposesTheConfiguredSource(): void
    {
        Expect::that(new HyperfPlugin('/project')->source())->toBeNull();
        Expect::that(new HyperfPlugin('/project', source: 'application')->source())->toBe('application');
    }

    #[Test]
    public function rejectsAnEmptySource(): void
    {
        Expect::that(static fn(): HyperfPlugin => new HyperfPlugin('/project', source: ''))
            ->toThrow(\InvalidArgumentException::class, message: 'Service source must not be empty.');
    }

    #[Test]
    public function aServiceWithoutAnIdUsesTheParameterType(): void
    {
        $service = new \ArrayObject();
        $plugin = $this->plugin([\ArrayObject::class => $service]);

        Expect::that($plugin->resolve(\ArrayObject::class, [new Service()]))->toBe($service);
    }

    #[Test]
    public function anExplicitIdEqualToTheMissingTypeFails(): void
    {
        $plugin = $this->plugin([]);

        Expect::that(static fn(): ?object => $plugin->resolve(\ArrayObject::class, [new Service(\ArrayObject::class)]))
            ->toThrow(HyperfBridgeError::class, matching: '/no service "ArrayObject"/');
    }

    #[Test]
    public function aServiceWithoutAnIdFailsWhenTheTypeIsMissing(): void
    {
        $plugin = $this->plugin([]);

        Expect::that(static fn(): ?object => $plugin->resolve(\ArrayObject::class, [new Service()]))
            ->toThrow(HyperfBridgeError::class, matching: '/no service "ArrayObject"/');
    }

    #[Test]
    public function anUnknownTypeWithoutAnAttributeReturnsNull(): void
    {
        Expect::that($this->plugin([])->resolve(\ArrayObject::class, []))->toBeNull();
    }

    /** @param array<string, mixed> $services */
    private function plugin(array $services): HyperfPlugin
    {
        $plugin = new HyperfPlugin('/project');
        new \ReflectionProperty($plugin, 'activeContainer')->setValue($plugin, new ArrayContainer($services));

        return $plugin;
    }
}
