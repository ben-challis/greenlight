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
        Expect::value(new HyperfPlugin('/project')->source())->toBeNull();
        Expect::value(new HyperfPlugin('/project', source: 'application')->source())->toBe('application');
    }

    #[Test]
    public function rejectsAnEmptySource(): void
    {
        Expect::calling(static fn(): HyperfPlugin => new HyperfPlugin('/project', source: ''))
            ->toThrow(\InvalidArgumentException::class, message: 'Service source must not be empty.');
    }

    #[Test]
    public function aServiceWithoutAnIdUsesTheParameterType(): void
    {
        $service = new \ArrayObject();
        $plugin = $this->plugin([\ArrayObject::class => $service]);

        Expect::value($plugin->resolve(\ArrayObject::class, [new Service()]))->toBe($service);
    }

    #[Test]
    public function anExplicitIdEqualToTheMissingTypeFails(): void
    {
        $plugin = $this->plugin([]);

        Expect::calling(static fn(): ?object => $plugin->resolve(\ArrayObject::class, [new Service(\ArrayObject::class)]))
            ->toThrow(HyperfBridgeError::class, matching: '/no service "ArrayObject"/');
    }

    #[Test]
    public function aServiceWithoutAnIdFailsWhenTheTypeIsMissing(): void
    {
        $plugin = $this->plugin([]);

        Expect::calling(static fn(): ?object => $plugin->resolve(\ArrayObject::class, [new Service()]))
            ->toThrow(HyperfBridgeError::class, matching: '/no service "ArrayObject"/');
    }

    #[Test]
    public function anUnknownTypeWithoutAnAttributeReturnsNull(): void
    {
        Expect::value($this->plugin([])->resolve(\ArrayObject::class, []))->toBeNull();
    }

    /** @param array<string, mixed> $services */
    private function plugin(array $services): HyperfPlugin
    {
        $plugin = new HyperfPlugin('/project');
        new \ReflectionProperty($plugin, 'activeContainer')->setValue($plugin, new ArrayContainer($services));

        return $plugin;
    }
}
