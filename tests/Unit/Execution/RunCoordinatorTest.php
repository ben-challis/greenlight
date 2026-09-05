<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\WorkerConfiguration;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Doubles\Fake;
use Greenlight\Event\EventSink;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Execution\Adapter\ProcessPoolExecution;
use Greenlight\Execution\ExecutionAdapter;
use Greenlight\Execution\ExecutionContext;
use Greenlight\Execution\ExecutionFailed;
use Greenlight\Execution\ExecutionOutcome;
use Greenlight\Execution\ExecutionTopology;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\RunCoordinator;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestSelection;
use Greenlight\Tests\Fixture\Plugins\RecordingRunSubscriber;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\ScriptedWorkerTransport;

final readonly class RunCoordinatorTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
    ) {}

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
                PhpSubprocess::command([$root . '/bin/greenlight']),
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
    public function processPoolProtocolFailuresUseTheExecutionFailureSeam(): void
    {
        $configuration = GreenlightConfig::create()->build();
        $resolved = ConfigurationResolver::resolve($configuration, new CliOverrides());
        $protocolFailure = ProtocolError::malformedFrame('scripted process-pool failure');

        Expect::that(fn() => $this->coordinator()->run(
            $resolved,
            $resolved->selection,
            [FixturePath::get('DiscoveryBasic')],
            new CollectingEventSink(),
            new ProcessPoolExecution(
                PhpSubprocess::command([\dirname(__DIR__, 3) . '/bin/greenlight']),
                $this->tempDirectory->path(),
                2,
                $resolved->workers,
                transport: new ScriptedWorkerTransport([], startFailure: $protocolFailure),
            ),
        ))->toThrow(static function (ExecutionFailed $error) use ($protocolFailure): void {
            Expect::that($error->getPrevious())
                ->because('the execution failure MUST preserve the process protocol failure')
                ->toBe($protocolFailure);
        });
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
        $first = $coordinator->run($resolved, $resolved->selection, [$fixtureDirectory], $firstSink, $this->execution($resolved->workers));
        $second = $coordinator->run($resolved, $resolved->selection, [$fixtureDirectory], $secondSink, $this->execution($resolved->workers));

        Expect::that($first->seed)
            ->because('the run result MUST report its explicit random seed')
            ->toBe(4242);
        Expect::that($second->seed)->toBe(4242);
        Expect::that($this->resultIds($firstSink))
            ->because('the same explicit seed MUST reproduce the coordinated run order')
            ->toBe($this->resultIds($secondSink));
    }

    #[Test]
    public function shardsPartitionARunWithoutLossOrDuplication(): void
    {
        $coordinator = $this->coordinator();
        $base = GreenlightConfig::create()->build();
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');
        $completeSink = new CollectingEventSink();

        $complete = ConfigurationResolver::resolve($base, new CliOverrides());
        $coordinator->run($complete, $complete->selection, [$fixtureDirectory], $completeSink, $this->execution($complete->workers));
        $completeIds = $this->resultIds($completeSink);
        $shardedIds = [];

        for ($index = 1; $index <= 3; ++$index) {
            $sink = new CollectingEventSink();
            $configuration = ConfigurationResolver::resolve(
                $base,
                new CliOverrides(selection: new TestSelection(shard: [$index, 3])),
            );
            $result = $coordinator->run($configuration, $configuration->selection, [$fixtureDirectory], $sink, $this->execution($configuration->workers));
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

    private function coordinator(): RunCoordinator
    {
        return new RunCoordinator($this->tempDirectory->path());
    }

    private function execution(WorkerConfiguration $configuration): ProcessPoolExecution
    {
        return new ProcessPoolExecution(
            PhpSubprocess::command([\dirname(__DIR__, 3) . '/bin/greenlight']),
            $this->tempDirectory->path(),
            1,
            $configuration,
        );
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
