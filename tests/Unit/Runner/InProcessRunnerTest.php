<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Core\Result\TestResult;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Runner\InProcessRunner;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Plugins\RecordingRunSubscriber;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class InProcessRunnerTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private EnvironmentVariables $environment,
    ) {}

    #[Test]
    public function runSubscribersObserveTheCompleteInProcessEventStream(): void
    {
        $subscriber = new RecordingRunSubscriber();
        $configuration = GreenlightConfig::create()->plugins($subscriber)->build();
        $sink = new CollectingEventSink();
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';

        $result = new InProcessRunner($this->tempDirectory->path())->run(
            $configuration,
            [$fixtureDirectory],
            $sink,
        );

        Expect::that($subscriber->events)
            ->because('run subscribers observe the same event stream as the configured sink')
            ->toBe($sink->events);

        Expect::that($subscriber->sequence())
            ->because('the subscriber observes both run boundaries')
            ->toContain('RunStarted')
            ->toContain('RunFinished');
        Expect::that($result->summary->passed)
            ->toBe(7);
    }

    #[Test]
    public function explicitSeedIsReportedAndReproducesTheRunOrder(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder(seed: 4242)->build();
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';
        $runner = new InProcessRunner($this->tempDirectory->path());
        $firstSink = new CollectingEventSink();
        $secondSink = new CollectingEventSink();

        $first = $runner->run($configuration, [$fixtureDirectory], $firstSink);
        $second = $runner->run($configuration, [$fixtureDirectory], $secondSink);

        Expect::that($first->seed)
            ->because('the run result MUST report its explicit random seed')
            ->toBe(4242);
        Expect::that($second->seed)
            ->toBe(4242);
        Expect::that($this->resultIds($firstSink))
            ->because('the same explicit seed MUST reproduce the in-process run order')
            ->toBe($this->resultIds($secondSink));
    }

    #[Test]
    public function shardsPartitionAnInProcessRunWithoutLossOrDuplication(): void
    {
        $runner = new InProcessRunner($this->tempDirectory->path());
        $base = GreenlightConfig::create()->build();
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';
        $completeSink = new CollectingEventSink();

        $runner->run($base, [$fixtureDirectory], $completeSink);
        $completeIds = $this->resultIds($completeSink);
        $shardedIds = [];

        for ($index = 1; $index <= 3; ++$index) {
            $sink = new CollectingEventSink();
            $configuration = ConfigurationResolver::resolve(
                $base,
                new CliOverrides(shard: [$index, 3]),
            );
            $result = $runner->run($configuration, [$fixtureDirectory], $sink);
            $ids = $this->resultIds($sink);

            Expect::that($result->plannedTests)
                ->because('each in-process shard reports the number of tests it executes')
                ->toBe(\count($ids));
            Expect::that($result->summary->total())
                ->toBe(\count($ids));

            $shardedIds = [...$shardedIds, ...$ids];
        }

        \sort($completeIds);
        \sort($shardedIds);

        Expect::that($shardedIds)
            ->because('all in-process shards reconstitute the complete run exactly once')
            ->toBe($completeIds);
    }

    #[Test]
    public function runRestoresTheCallerChannelEnvironment(): void
    {
        $this->environment->set('GREENLIGHT_CHANNEL', 'caller-channel');
        $configuration = GreenlightConfig::create()->build();
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';

        new InProcessRunner($this->tempDirectory->path())->run(
            $configuration,
            [$fixtureDirectory],
            new CollectingEventSink(),
        );

        Expect::that(\getenv('GREENLIGHT_CHANNEL'))
            ->because('an in-process run MUST restore the caller process environment')
            ->toBe('caller-channel');
        Expect::that($_ENV['GREENLIGHT_CHANNEL'] ?? null)
            ->toBe('caller-channel');
        Expect::that($_SERVER['GREENLIGHT_CHANNEL'] ?? null)
            ->toBe('caller-channel');
    }

    #[Test]
    public function workerBootstrapFailuresUseTheWorkerFatalProtocolError(): void
    {
        $this->environment->unset('GREENLIGHT_CHANNEL');
        $failure = new \RuntimeException('worker bootstrap exploded');
        $plugin = new readonly class ($failure) implements WorkerBootstrapSubscriber, Fake {
            public function __construct(private \RuntimeException $failure) {}

            #[\Override]
            public function onWorkerBootstrap(WorkerBootstrapContext $context): void
            {
                throw $this->failure;
            }
        };
        $configuration = GreenlightConfig::create()->plugins($plugin)->build();
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';

        Expect::that(fn() => new InProcessRunner($this->tempDirectory->path())->run(
            $configuration,
            [$fixtureDirectory],
            new CollectingEventSink(),
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
            ->because('a failed in-process run MUST restore an absent caller environment value')
            ->toBeFalse();
        Expect::that(\array_key_exists('GREENLIGHT_CHANNEL', $_ENV))
            ->toBeFalse();
        Expect::that(\array_key_exists('GREENLIGHT_CHANNEL', $_SERVER))
            ->toBeFalse();
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
