<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Core\Result\TestResult;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\InProcessRunner;
use Greenlight\Tests\Fixture\Plugins\RecordingRunSubscriber;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class InProcessRunnerTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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
            ->toContain('RunFinished')
            ->and($result->summary->passed)
            ->toBe(7);
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
                ->toBe(\count($ids))
                ->and($result->summary->total())
                ->toBe(\count($ids));

            $shardedIds = [...$shardedIds, ...$ids];
        }

        \sort($completeIds);
        \sort($shardedIds);

        Expect::that($shardedIds)
            ->because('all in-process shards reconstitute the complete run exactly once')
            ->toBe($completeIds);
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
