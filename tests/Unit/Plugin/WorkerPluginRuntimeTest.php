<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Event\RunStarted;
use Greenlight\Execution\Plugin\OrchestratorPluginRuntime;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\TestInstanceLeakDetector;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Plugin\WorkerRuntimeRunner;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Plugins\FakeCapabilityPlugin;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;
use Greenlight\Tests\Fixture\Plugins\RecordingRunSubscriber;
use Greenlight\Tests\Support\CollectingEventSink;

final class WorkerPluginRuntimeTest
{
    #[Test]
    public function emptyRuntimeRunsLifecycleCallbacksDirectly(): void
    {
        $runtime = WorkerPluginRuntime::fromDefinitions([]);

        Expect::that($runtime->runWorker(static fn(): string => 'worker result'))
            ->toBe('worker result');
        Expect::that($runtime->runTestAttempt(static fn(): string => 'attempt result'))
            ->toBe('attempt result');
        Expect::that(WorkerPluginRuntime::requiresInitialBootstrapBarrier([]))
            ->toBeFalse();
    }

    #[Test]
    public function definitionsCreateOnlyOwnedInstancesAndSeparateMixedCapabilities(): void
    {
        $mixedInstances = [];
        $orchestratorConstructions = 0;
        $workerConstructions = 0;
        $definitions = [
            PluginDefinition::fromFactory(
                static function () use (&$mixedInstances): FakeCapabilityPlugin {
                    $plugin = new FakeCapabilityPlugin();
                    $mixedInstances[] = $plugin;

                    return $plugin;
                },
            ),
            PluginDefinition::fromFactory(
                static function () use (&$orchestratorConstructions): RecordingRunSubscriber {
                    ++$orchestratorConstructions;

                    return new RecordingRunSubscriber();
                },
            ),
            PluginDefinition::fromFactory(
                static function () use (&$workerConstructions): QuarantinePlugin {
                    ++$workerConstructions;

                    return new QuarantinePlugin();
                },
            ),
        ];
        $sink = new CollectingEventSink();

        $orchestrator = OrchestratorPluginRuntime::fromDefinitions($definitions, $sink);
        WorkerPluginRuntime::fromDefinitions($definitions);
        $event = new RunStarted('run-1', 1, 1, 1.0);
        $orchestrator->emit($event);

        Expect::that($orchestratorConstructions)->toBe(1);
        Expect::that($workerConstructions)->toBe(1);
        Expect::that($mixedInstances)->toHaveCount(2);
        Expect::that($mixedInstances[1])
            ->because('mixed-capability plugins MUST use a separate instance on each side')
            ->not()
            ->toBe($mixedInstances[0]);
        Expect::that($sink->events)->toBe([$event]);
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
        $runtime = WorkerPluginRuntime::fromPlugins([
            $runner('late', 10),
            $runner('early', -10),
        ]);

        $result = $runtime->runWorker(function () use ($events): string {
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

    #[Test]
    public function runtimeKeepsThePrioritySnapshotForItsOwnerLifetime(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $first = new MutablePriorityRunnerProbe($events, 'first', 0);
        $second = new MutablePriorityRunnerProbe($events, 'second', 10);
        $runtime = WorkerPluginRuntime::fromPlugins([$first, $second]);
        $first->priority = 20;
        $second->priority = -20;

        $runtime->runWorker(static function () use ($events): void {
            $events->append('worker');
        });

        Expect::that($events->getArrayCopy())->toBe([
            'first:enter',
            'second:enter',
            'worker',
            'second:exit',
            'first:exit',
        ]);
    }

    #[Test]
    public function assignmentLeakDetectorsJoinTheStableCapabilityOrder(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $id = new TestId('Example\\LeakyTest', 'retainsItself');
        $instance = new \stdClass();
        $configured = new RecordingLeakDetectorProbe($events, 'configured', 0, $id);
        $bundled = new RecordingLeakDetectorProbe($events, 'bundled', -10, $id);
        $runtime = WorkerPluginRuntime::fromPlugins([$configured])
            ->withBundledPlugins([$bundled]);

        $runtime->watchTestInstance($id, $instance);
        $leaks = $runtime->detectedLeaks();

        Expect::that($events->getArrayCopy())->toBe([
            'bundled:watch',
            'configured:watch',
            'bundled:sweep',
            'configured:sweep',
        ]);
        Expect::that($leaks)
            ->because('multiple detectors MUST NOT duplicate one leaked test')
            ->toBe([$id]);
    }

    #[Test]
    public function bootstrapCapabilityRequiresTheInitialBarrierWithoutConstruction(): void
    {
        $constructions = 0;
        $definitions = [PluginDefinition::fromFactory(
            static function () use (&$constructions): BootstrapPluginProbe {
                ++$constructions;

                return new BootstrapPluginProbe();
            },
        )];

        Expect::that(WorkerPluginRuntime::requiresInitialBootstrapBarrier($definitions))
            ->because('the orchestrator MUST inspect bootstrap capability metadata without creating worker plugins')
            ->toBeTrue();
        Expect::that($constructions)->toBe(0);
    }
}

final readonly class BootstrapPluginProbe implements Fake, WorkerBootstrapSubscriber
{
    #[\Override]
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void {}
}

final class MutablePriorityRunnerProbe implements Fake, Prioritized, WorkerRuntimeRunner
{
    /** @param \ArrayObject<int, string> $events */
    public function __construct(
        private readonly \ArrayObject $events,
        private readonly string $name,
        public int $priority,
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
}

final readonly class RecordingLeakDetectorProbe implements Fake, Prioritized, TestInstanceLeakDetector
{
    /** @param \ArrayObject<int, string> $events */
    public function __construct(
        private \ArrayObject $events,
        private string $name,
        private int $priority,
        private TestId $leak,
    ) {}

    #[\Override]
    public function priority(): int
    {
        return $this->priority;
    }

    #[\Override]
    public function watch(TestId $id, object $instance): void
    {
        $this->events->append($this->name . ':watch');
    }

    #[\Override]
    public function sweep(): array
    {
        $this->events->append($this->name . ':sweep');

        return [$this->leak];
    }
}
