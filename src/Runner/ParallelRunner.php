<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Config\Configuration;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Runner\Worker\EventSink;

/**
 * Runs discovery plus the process pool.
 *
 * This is the default runner; workers=1 falls back to the in-process runner
 * at the call site.
 *
 * @internal
 */
final readonly class ParallelRunner
{
    /**
     * @param non-empty-list<non-empty-string> $workerCommand argv prefix invoking bin/greenlight
     */
    public function __construct(
        private array $workerCommand,
        private string $workingDirectory,
    ) {}

    /**
     * @param list<non-empty-string> $directories
     * @param positive-int $workerCount
     * @param list<non-empty-string> $priorityClasses classes to run first, in the given order
     * @param array<string, float> $classSeconds recorded class durations for longest-first ordering; ignored on seeded runs
     *
     * @throws DiscoveryError
     */
    public function run(
        Configuration $configuration,
        array $directories,
        EventSink $sink,
        int $workerCount,
        ?CoverageSettings $coverageSettings = null,
        ?string $configFile = null,
        bool $detectLeaks = false,
        array $priorityClasses = [],
        array $classSeconds = [],
        ?GracefulShutdown $shutdown = null,
    ): RunResult {
        $seed = null;

        if ($configuration->randomizeOrder) {
            $seed = $configuration->randomSeed ?? \random_int(0, 2 ** 31 - 1);
        }

        $filter = SelectionFilter::fromConfiguration($configuration);
        $plan = PlanOrder::schedule(
            $this->sharded(new TestDiscoverer()->discover($directories, $filter, $seed, DiscoveryCache::forDirectories($directories)), $configuration),
            $priorityClasses,
            $configuration->randomizeOrder ? [] : $classSeconds,
        );

        $runId = \bin2hex(\random_bytes(8));
        $startedAt = \hrtime(true);

        $orchestratorSide = PluginRegistry::orchestratorSide($configuration->plugins);

        if ($orchestratorSide->runSubscribers() !== []) {
            $sink = new PluginEventSink($orchestratorSide, $sink);
        }

        $sink->emit(new RunStarted($runId, \count($plan), $workerCount, \microtime(true)));

        $orchestrator = new Orchestrator(
            $this->workerCommand,
            $this->workingDirectory,
            $configuration->recycleAfterTests,
            $configuration->recycleAboveMemoryBytes,
            $configuration->stopAfterFailures,
            $coverageSettings,
            $configFile,
            $detectLeaks,
            $configuration->policy->isNoOp() ? null : $configuration->policy,
            $shutdown,
        );

        $summary = $orchestrator->run($plan, $sink, $workerCount);

        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $sink->emit(new RunFinished($runId, $summary, $durationSeconds, \microtime(true)));

        return new RunResult($summary, \count($plan), $durationSeconds, $seed, $orchestrator->collectedCoverage(), $orchestrator->detectedLeaks());
    }

    private function sharded(ExecutionPlan $plan, Configuration $configuration): ExecutionPlan
    {
        if ($configuration->shard === null) {
            return $plan;
        }

        [$index, $count] = $configuration->shard;

        return PlanShard::select($plan, \max(1, $index), \max(1, $count));
    }

}
