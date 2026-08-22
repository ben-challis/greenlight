<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestSelection;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Runner\Execution\ExecutionAdapter;
use Greenlight\Runner\Execution\ExecutionContext;
use Greenlight\Runner\Execution\ExecutionOutcome;
use Greenlight\Runner\Execution\ExecutionTopology;
use Greenlight\Runner\Execution\InProcessExecution;
use Greenlight\Runner\Execution\ProcessPoolExecution;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\RunCoordinator;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Plugins\RecordingRunSubscriber;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

final readonly class RunCoordinatorTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private EnvironmentVariables $environment,
    ) {}

    #[Test]
    public function runSubscribersObserveTheCompleteInProcessEventStream(): void
    {
        $subscriber = null;
        $configuration = GreenlightConfig::create()->plugins(
            static function () use (&$subscriber): RecordingRunSubscriber {
                return $subscriber = new RecordingRunSubscriber();
            },
        )->build();
        $sink = new CollectingEventSink();
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');

        $resolved = ConfigurationResolver::resolve($configuration, new CliOverrides());
        $result = $this->coordinator()->run(
            $resolved,
            $resolved->selection,
            [$fixtureDirectory],
            $sink,
            new InProcessExecution(),
        );

        Expect::that($subscriber?->events)
            ->because('run subscribers observe the same event stream as the configured sink')
            ->toBe($sink->events);
        Expect::that($subscriber?->sequence())
            ->because('the subscriber observes both run boundaries')
            ->toContain('RunStarted')
            ->toContain('RunFinished');
        Expect::that($result->summary->passed)->toBe(7);
    }

    #[Test]
    public function runSubscribersObserveTheCompleteProcessPoolEventStream(): void
    {
        $subscriber = null;
        $configuration = GreenlightConfig::create()->plugins(
            static function () use (&$subscriber): RecordingRunSubscriber {
                return $subscriber = new RecordingRunSubscriber();
            },
        )->build();
        $sink = new CollectingEventSink();
        $root = \dirname(__DIR__, 3);
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');
        $resolved = ConfigurationResolver::resolve($configuration, new CliOverrides());

        $result = $this->coordinator()->run(
            $resolved,
            $resolved->selection,
            [$fixtureDirectory],
            $sink,
            new ProcessPoolExecution(
                [\PHP_BINARY, $root . '/bin/greenlight'],
                $this->tempDirectory->path(),
                2,
                $resolved->workers,
            ),
        );

        Expect::that($subscriber?->events)
            ->because('process-pool run subscribers observe the configured sink event stream')
            ->toBe($sink->events);
        Expect::that($subscriber?->sequence())
            ->because('the process-pool subscriber observes both run boundaries')
            ->toContain('RunStarted')
            ->toContain('RunFinished');
        Expect::that($result->summary->passed)->toBe(7);
    }

    #[Test]
    public function emptyPlansEmitRunBoundariesWithoutCallingTheExecutionAdapter(): void
    {
        $execution = new class implements ExecutionAdapter, Fake {
            public int $executeCalls = 0;

            #[\Override]
            public function topology(
                ExecutionPlan $plan,
                array $classSeconds,
            ): ExecutionTopology {
                return new ExecutionTopology(3, 1);
            }

            #[\Override]
            public function execute(
                ExecutionPlan $plan,
                EventSink $sink,
                ExecutionContext $context,
            ): ExecutionOutcome {
                ++$this->executeCalls;

                return new ExecutionOutcome(new ResultSummary());
            }
        };
        $sink = new CollectingEventSink();

        $configuration = ConfigurationResolver::resolve(GreenlightConfig::create()->build(), new CliOverrides());
        $result = $this->coordinator()->run(
            $configuration,
            $configuration->selection,
            [$this->tempDirectory->subdirectory('empty-plan')],
            $sink,
            $execution,
        );

        Expect::that($execution->executeCalls)
            ->because('an empty plan MUST NOT start its execution adapter')
            ->toBe(0);
        Expect::that($sink->sequence())->toBe(['RunStarted', 'RunFinished']);
        Expect::that($sink->events[0])->toBeInstanceOf(RunStarted::class);
        Expect::that($sink->events[1])->toBeInstanceOf(RunFinished::class);

        $started = $sink->events[0];
        $finished = $sink->events[1];

        Expect::that($started->workers)->toBe(3);
        Expect::that($started->plannedTests)->toBe(0);
        Expect::that($finished->runId)->toBe($started->runId);
        Expect::that($result->plannedTests)->toBe(0);
    }

    #[Test]
    public function explicitSeedIsReportedAndReproducesTheRunOrder(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder(seed: 4242)->build();
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');
        $coordinator = $this->coordinator();
        $firstSink = new CollectingEventSink();
        $secondSink = new CollectingEventSink();

        $resolved = ConfigurationResolver::resolve($configuration, new CliOverrides());
        $first = $coordinator->run($resolved, $resolved->selection, [$fixtureDirectory], $firstSink, new InProcessExecution());
        $second = $coordinator->run($resolved, $resolved->selection, [$fixtureDirectory], $secondSink, new InProcessExecution());

        Expect::that($first->seed)
            ->because('the run result MUST report its explicit random seed')
            ->toBe(4242);
        Expect::that($second->seed)->toBe(4242);
        Expect::that($this->resultIds($firstSink))
            ->because('the same explicit seed MUST reproduce the coordinated run order')
            ->toBe($this->resultIds($secondSink));
    }

    #[Test]
    public function shardsPartitionAnInProcessRunWithoutLossOrDuplication(): void
    {
        $coordinator = $this->coordinator();
        $base = GreenlightConfig::create()->build();
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');
        $completeSink = new CollectingEventSink();

        $complete = ConfigurationResolver::resolve($base, new CliOverrides());
        $coordinator->run($complete, $complete->selection, [$fixtureDirectory], $completeSink, new InProcessExecution());
        $completeIds = $this->resultIds($completeSink);
        $shardedIds = [];

        for ($index = 1; $index <= 3; ++$index) {
            $sink = new CollectingEventSink();
            $configuration = ConfigurationResolver::resolve(
                $base,
                new CliOverrides(selection: new TestSelection(shard: [$index, 3])),
            );
            $result = $coordinator->run($configuration, $configuration->selection, [$fixtureDirectory], $sink, new InProcessExecution());
            $ids = $this->resultIds($sink);

            Expect::that($result->plannedTests)
                ->because('each coordinated shard reports the number of tests it executes')
                ->toBe(\count($ids));
            Expect::that($result->summary->total())->toBe(\count($ids));

            $shardedIds = [...$shardedIds, ...$ids];
        }

        \sort($completeIds);
        \sort($shardedIds);

        Expect::that($shardedIds)
            ->because('all coordinated shards reconstitute the complete run exactly once')
            ->toBe($completeIds);
    }

    #[Test]
    public function inProcessExecutionRestoresTheCallerChannelEnvironment(): void
    {
        $this->environment->set('GREENLIGHT_CHANNEL', 'caller-channel');
        $configuration = GreenlightConfig::create()->build();
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');

        $resolved = ConfigurationResolver::resolve($configuration, new CliOverrides());
        $this->coordinator()->run(
            $resolved,
            $resolved->selection,
            [$fixtureDirectory],
            new CollectingEventSink(),
            new InProcessExecution(),
        );

        Expect::that(\getenv('GREENLIGHT_CHANNEL'))
            ->because('in-process execution MUST restore the caller process environment')
            ->toBe('caller-channel');
        Expect::that($_ENV['GREENLIGHT_CHANNEL'] ?? null)->toBe('caller-channel');
        Expect::that($_SERVER['GREENLIGHT_CHANNEL'] ?? null)->toBe('caller-channel');
    }

    #[Test]
    public function workerBootstrapFailuresUseTheWorkerFatalProtocolError(): void
    {
        $this->environment->unset('GREENLIGHT_CHANNEL');
        $failure = new \RuntimeException('worker bootstrap exploded');
        $configuration = GreenlightConfig::create()->plugins(
            static fn(): FailingWorkerBootstrapPlugin => new FailingWorkerBootstrapPlugin($failure),
        )->build();
        $resolved = ConfigurationResolver::resolve($configuration, new CliOverrides());
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');

        Expect::that(fn() => $this->coordinator()->run(
            $resolved,
            $resolved->selection,
            [$fixtureDirectory],
            new CollectingEventSink(),
            new InProcessExecution(),
        ))
            ->because('in-process bootstrap failures MUST use the worker fatal protocol contract')
            ->toThrow(
                static function (ProtocolError $error) use ($failure): void {
                    Expect::that($error->getMessage())->toBe(\sprintf(
                        'Worker "in-process" reported a fatal Greenlight error: worker bootstrap exploded (%s:%d)',
                        $failure->getFile(),
                        $failure->getLine(),
                    ));
                    Expect::that($error->getPrevious())->toBe($failure);
                },
            );

        Expect::that(\getenv('GREENLIGHT_CHANNEL'))
            ->because('failed in-process execution MUST restore an absent caller environment value')
            ->toBeFalse();
        Expect::that(\array_key_exists('GREENLIGHT_CHANNEL', $_ENV))->toBeFalse();
        Expect::that(\array_key_exists('GREENLIGHT_CHANNEL', $_SERVER))->toBeFalse();
    }

    private function coordinator(): RunCoordinator
    {
        return new RunCoordinator($this->tempDirectory->path());
    }

    /**
     * @return list<string>
     */
    private function resultIds(CollectingEventSink $sink): array
    {
        return \array_map(
            static fn(TestResult $result): string => (string) $result->id,
            $sink->results(),
        );
    }
}

final readonly class FailingWorkerBootstrapPlugin implements WorkerBootstrapSubscriber, Fake
{
    public function __construct(private \RuntimeException $failure) {}

    #[\Override]
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void
    {
        throw $this->failure;
    }
}
