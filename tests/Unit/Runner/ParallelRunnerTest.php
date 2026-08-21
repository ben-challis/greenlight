<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Runner\ParallelRunner;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Plugins\RecordingRunSubscriber;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class ParallelRunnerTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function runSubscribersObserveTheCompleteParallelEventStream(): void
    {
        $subscriber = new RecordingRunSubscriber();
        $configuration = GreenlightConfig::create()->plugins($subscriber)->build();
        $sink = new CollectingEventSink();
        $root = \dirname(__DIR__, 3);
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';

        $result = new ParallelRunner(
            [\PHP_BINARY, $root . '/bin/greenlight'],
            $this->tempDirectory->path(),
        )->run(
            $configuration,
            [$fixtureDirectory],
            $sink,
            workerCount: 2,
        );

        Expect::that($subscriber->events)
            ->because('parallel run subscribers observe the configured sink event stream')
            ->toBe($sink->events);

        Expect::that($subscriber->sequence())
            ->because('the parallel subscriber observes both run boundaries')
            ->toContain('RunStarted')
            ->toContain('RunFinished');
        Expect::that($result->summary->passed)
            ->toBe(7);
    }
}
