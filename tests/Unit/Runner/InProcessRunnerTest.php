<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Config\GreenlightConfig;
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
    public function inProcessShardsPartitionTheDiscoveredSuite(): void
    {
        $configuration = GreenlightConfig::create()->build();
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';
        $ids = [];
        $plannedTests = 0;

        foreach ([1, 2] as $index) {
            $sink = new CollectingEventSink();
            $shard = ConfigurationResolver::resolve(
                $configuration,
                new CliOverrides(shard: [$index, 2]),
            );
            $result = new InProcessRunner($this->tempDirectory->path())->run(
                $shard,
                [$fixtureDirectory],
                $sink,
            );
            $plannedTests += $result->plannedTests;

            foreach ($sink->results() as $testResult) {
                $ids[] = (string) $testResult->id;
            }
        }

        Expect::that($plannedTests)
            ->because('both in-process shards reconstitute the discovered suite')
            ->toBe(7)
            ->and($ids)
            ->toHaveCount(7)
            ->and(\array_values(\array_unique($ids)))
            ->toHaveCount(7);
    }
}
