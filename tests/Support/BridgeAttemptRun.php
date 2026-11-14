<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\Worker\Worker;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestChannel;
use Greenlight\Test\TestDefinition;

/**
 * Runs test methods in the given order in one in-process worker with one
 * bridge plugin.
 */
final class BridgeAttemptRun
{
    private function __construct() {}

    /**
     * @param list<array{class-string, non-empty-string}> $tests
     *
     * @return list<TestResult>
     */
    public static function run(Plugin $plugin, array $tests): array
    {
        $runtime = WorkerPluginRuntime::fromPlugins([$plugin]);
        $scopes = $runtime->prepareWorker(
            new WorkerBootstrapContext('bridge-attempt-end', new TestChannel(1), IntegrationResources::empty()),
            [],
        );
        $sink = new CollectingEventSink();
        $entries = \array_map(
            static fn(array $test): PlanEntry => new PlanEntry(new TestDefinition($test[0], $test[1])),
            $tests,
        );

        try {
            new Worker([], $runtime)->run(new ExecutionPlan($entries), $sink, scopes: $scopes);
        } finally {
            $scopes->closeWorker();
        }

        return $sink->results();
    }
}
