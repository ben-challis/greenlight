<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\TestResult;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\BeforeTestSubscriber;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Plugin\WorkerRuntimeRunner;
use Greenlight\Tests\Fixture\Plugins\FakeCapabilityPlugin;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;
use Greenlight\Tests\Fixture\Plugins\PrioritizedFakeCapabilityPlugin;

final class PluginRegistryTest
{
    #[Test]
    public function emptyRegistryExposesNoCapabilitiesOrHarnessServices(): void
    {
        $registry = PluginRegistry::none();

        Expect::that($registry->beforeTestSubscribers())
            ->because('an empty registry MUST expose no plugin capabilities or harness services')
            ->toBe([]);
        Expect::that($registry->afterTestSubscribers())
            ->toBe([]);
        Expect::that($registry->retryDeciders())
            ->toBe([]);
        Expect::that($registry->runSubscribers())
            ->toBe([]);
        Expect::that($registry->harnessServices())
            ->toBe([]);
        Expect::that($registry->serviceResolvers())
            ->toBe([]);
        Expect::that($registry->runWorker(static fn(): string => 'worker result'))
            ->toBe('worker result');
        Expect::that($registry->hasWorkerBootstrapSubscribers())
            ->toBeFalse();
    }

    #[Test]
    public function registryReportsWorkerBootstrapSubscribers(): void
    {
        $subscriber = new readonly class implements Fake, WorkerBootstrapSubscriber {
            #[\Override]
            public function onWorkerBootstrap(WorkerBootstrapContext $context): void {}
        };

        Expect::that(new PluginRegistry([$subscriber])->hasWorkerBootstrapSubscribers())
            ->because('worker bootstrap subscribers require the initial ready barrier')
            ->toBeTrue();
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

        Expect::that($registry->beforeTestSubscribers())
            ->because('capability accessors filter plugins and keep stable priority order')
            ->toBe($expected);
        Expect::that($registry->afterTestSubscribers())
            ->because('after subscribers MUST unwind priority and registration order')
            ->toBe(\array_reverse($expected));
        Expect::that($registry->retryDeciders())->toBe($expected);
        Expect::that($registry->runSubscribers())->toBe($expected);
        Expect::that($registry->serviceResolvers())->toBe($expected);
        Expect::that($registry->ofType(NamedFakePlugin::class))->toBe([$unrelated]);
    }

    #[Test]
    public function testSubscriberCapabilitiesAreIndependent(): void
    {
        $before = new readonly class implements BeforeTestSubscriber, Fake {
            #[\Override]
            public function beforeTest(TestContext $context): void {}
        };
        $after = new readonly class implements AfterTestSubscriber, Fake {
            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };
        $registry = new PluginRegistry([$before, $after]);

        Expect::that($registry->beforeTestSubscribers())
            ->because('a before subscriber MUST NOT require an after callback')
            ->toBe([$before]);
        Expect::that($registry->afterTestSubscribers())
            ->because('an after subscriber MUST NOT require a before callback')
            ->toBe([$after]);
    }

    #[Test]
    public function workerRuntimeBoundariesNestInPriorityOrder(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $runner = (static fn(string $name, int $priority): WorkerRuntimeRunner => new readonly class ($events, $name, $priority) implements Fake, Prioritized, WorkerRuntimeRunner {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(
                private \ArrayObject $events,
                private string $name,
                private int $priority,
            ) {}

            #[\Override]
            public function priority(): int
            {
                return $this->priority;
            }

            #[\Override]
            public function runWorker(\Closure $worker): mixed
            {
                $this->events->append($this->name . ':enter');

                try {
                    return $worker();
                } finally {
                    $this->events->append($this->name . ':exit');
                }
            }
        });
        $late = $runner('late', 10);
        $early = $runner('early', -10);
        $registry = new PluginRegistry([$late, $early]);

        $result = $registry->runWorker(function () use ($events): string {
            $events->append('worker');

            return 'result';
        });

        Expect::that($result)->toBe('result');
        Expect::that($events->getArrayCopy())->toBe([
            'early:enter',
            'late:enter',
            'worker',
            'late:exit',
            'early:exit',
        ]);
    }
}
