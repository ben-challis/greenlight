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
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Runner\Worker\LeakDetector;
use Greenlight\Runner\Worker\Worker;

/**
 * Runs discovery plus one in-process worker and returns a summary.
 *
 * This is the whole runner until the process pool exists; after that it
 * remains the workers=1 fallback for hosts without process support.
 *
 * @internal
 */
final readonly class InProcessRunner
{
    /**
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $priorityClasses classes to run first, in the given order
     * @param array<string, float> $classSeconds recorded class durations for longest-first ordering; ignored on seeded runs
     *
     * @throws DiscoveryError
     */
    public function run(
        Configuration $configuration,
        array $directories,
        EventSink $sink,
        ?CoverageSettings $coverageSettings = null,
        bool $detectLeaks = false,
        array $priorityClasses = [],
        array $classSeconds = [],
        ?GracefulShutdown $shutdown = null,
    ): RunResult {
        $seed = null;

        if ($configuration->randomizeOrder) {
            $seed = $configuration->randomSeed ?? \random_int(0, 2 ** 31 - 1);
        }

        $plan = PlanOrder::schedule(
            $this->sharded($this->discover($configuration, $directories, $seed), $configuration),
            $priorityClasses,
            $configuration->randomizeOrder ? [] : $classSeconds,
        );

        $runId = \bin2hex(\random_bytes(8));
        $startedAt = \hrtime(true);

        $plugins = PluginRegistry::forWorker($configuration->plugins);
        $orchestratorSide = PluginRegistry::orchestratorSide($configuration->plugins);

        if ($orchestratorSide->runSubscribers() !== []) {
            $sink = new PluginEventSink($orchestratorSide, $sink);
        }

        $sink->emit(new RunStarted($runId, \count($plan), 1, \microtime(true)));

        $collector = $coverageSettings instanceof CoverageSettings ? CoverageCollector::create($coverageSettings) : null;
        $collector?->start();

        // A single in-process worker is always channel 1. Setting the
        // variable, rather than relying on its absence, overrides any value
        // inherited from an outer Greenlight run spawning this one.
        \putenv('GREENLIGHT_CHANNEL=1');

        $outcome = new Worker(DefaultServices::registry($plugins), $plugins, $detectLeaks ? new LeakDetector() : null, 'in-process', $configuration->policy->isNoOp() ? null : $configuration->policy)
            ->run(
                $plan,
                $sink,
                $configuration->stopAfterFailures,
                null,
                $shutdown instanceof GracefulShutdown ? $shutdown->requested(...) : null,
            );
        $summary = $outcome->summary;

        $coverage = $collector?->stop();

        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $sink->emit(new RunFinished($runId, $summary, $durationSeconds, \microtime(true)));

        return new RunResult($summary, \count($plan), $durationSeconds, $seed, $coverage, $outcome->leaks);
    }

    /**
     * @param list<non-empty-string> $directories
     */
    private function discover(Configuration $configuration, array $directories, ?int $seed): ExecutionPlan
    {
        $filter = new Filter(
            includeGroups: $configuration->groups,
            excludeGroups: $configuration->excludeGroups,
            excludeClasses: $configuration->excludeClasses,
            excludeMethods: $configuration->excludeMethods,
            excludePaths: $configuration->excludePaths,
            includeIds: $configuration->filters,
            includeExactIds: $configuration->onlyTests ?? [],
        );

        return new TestDiscoverer()->discover($directories, $filter, $seed, DiscoveryCache::forDirectories($directories));
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
