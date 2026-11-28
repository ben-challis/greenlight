<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanOrder;
use Greenlight\Discovery\Plan\PlanShard;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Event\EventSink;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Execution\Artifact\ArtifactPruneReport;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Plugin\OrchestratorPluginRuntime;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\IntegrationFixture\IntegrationFixtureManager;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestSelection;

/**
 * Coordinates discovery, run-wide resources, lifecycle events, and execution.
 *
 * @internal
 */
final readonly class RunCoordinator
{
    public function __construct(private string $workingDirectory) {}

    /**
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $priorityClasses Classes that run first in the specified order.
     * @param array<string, float> $classSeconds Recorded class durations for longest-first ordering. Seeded runs ignore this value.
     *
     * @throws DiscoveryError
     * @throws AttachmentError
     * @throws IntegrationFixtureError
     * @throws ExecutionFailed
     * @throws ReportGenerationFailed
     */
    public function run(
        ResolvedConfiguration $configuration,
        TestSelection $selection,
        array $directories,
        EventSink $sink,
        ExecutionAdapter $execution,
        array $priorityClasses = [],
        array $classSeconds = [],
    ): RunResult {
        $seed = $configuration->order->seed;
        $classSeconds = $configuration->order->isRandomized() ? [] : $classSeconds;
        $storage = StorageLayout::resolve($configuration->storage, $this->workingDirectory);
        $discovered = $this->discover($selection, $directories, $seed, $storage);
        $plan = $this->sharded($discovered, $selection);
        // Hash sharding can give a shard no tests when the suite has fewer
        // classes than shards. That shard has no work, which is not an error.
        $emptyShard = $selection->shard !== null && \count($plan) === 0 && \count($discovered) > 0;
        $orchestratorPlugins = OrchestratorPluginRuntime::fromDefinitions(
            $configuration->execution->plugins,
            $sink,
            [new PlanOrder($priorityClasses, $classSeconds)],
        );
        try {
            $plan = $orchestratorPlugins->transformTestPlan($plan);
        } catch (PluginRuntimeError $failure) {
            throw ExecutionFailed::plugin($failure);
        }
        $topology = $execution->topology($plan, $classSeconds);
        $runId = \bin2hex(\random_bytes(8));
        $startedAt = \hrtime(true);
        $artifacts = ArtifactStore::open(
            $configuration->execution->artifacts,
            $this->workingDirectory,
            $runId,
            temporaryDirectory: $storage->temporaryDirectory,
            retainAttachment: $orchestratorPlugins->retainAttachment(...),
        );

        try {
            $sink = $orchestratorPlugins;

            if (\count($plan) === 0) {
                $sink->emit(new RunStarted(
                    $runId,
                    0,
                    $topology->workers,
                    \microtime(true),
                    $artifacts->publicDirectory(),
                ));
                $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
                $summary = new ResultSummary();
                $sink->emit(new RunFinished($runId, $summary, $durationSeconds, \microtime(true)));

                return new RunResult(
                    $summary,
                    0,
                    $durationSeconds,
                    $seed,
                    artifactMaintenance: $this->maintenanceReport($artifacts->complete()),
                    emptyShard: $emptyShard,
                );
            }

            $fixtures = IntegrationFixtureManager::provision(
                $orchestratorPlugins->fixtureDefinitions(),
                $runId,
                $topology->workers,
                $topology->fixtureChannels,
                $selection->shard,
            );

            try {
                $sink->emit(new RunStarted(
                    $runId,
                    \count($plan),
                    $topology->workers,
                    \microtime(true),
                    $artifacts->publicDirectory(),
                ));
                $outcome = $execution->execute(
                    $plan,
                    $sink,
                    new ExecutionContext(
                        $configuration->execution,
                        $artifacts,
                        $fixtures,
                        $classSeconds,
                        $storage,
                    ),
                );
                $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
                $sink->emit(new RunFinished(
                    $runId,
                    $outcome->summary,
                    $durationSeconds,
                    \microtime(true),
                    $outcome->workerTimings,
                ));
            } catch (\Throwable $failure) {
                $cleanupFailures = $fixtures->close();

                if ($cleanupFailures !== []) {
                    throw IntegrationFixtureError::afterFailure($failure, $cleanupFailures);
                }

                throw $failure;
            }

            $cleanupFailures = $fixtures->close();

            if ($cleanupFailures !== []) {
                throw IntegrationFixtureError::cleanup($cleanupFailures);
            }

            return new RunResult(
                $outcome->summary,
                \count($plan),
                $durationSeconds,
                $seed,
                $outcome->coverage,
                $outcome->leaks,
                $this->maintenanceReport($artifacts->complete()),
                warnings: $outcome->warnings,
            );
        } finally {
            $artifacts->cleanup();
        }
    }

    private function maintenanceReport(ArtifactPruneReport $report): ArtifactMaintenanceReport
    {
        $items = [];

        foreach ($report->items as $item) {
            $items[] = new ArtifactMaintenanceItem($item->directory, $item->bytes, $item->reasons);
        }

        return new ArtifactMaintenanceReport($items, $report->warnings, $report->dryRun);
    }

    /**
     * @param list<non-empty-string> $directories
     * @throws DiscoveryError
     */
    private function discover(
        TestSelection $selection,
        array $directories,
        ?int $seed,
        StorageLayout $storage,
    ): ExecutionPlan {
        return new TestDiscoverer()->discover(
            $directories,
            $selection,
            $seed,
            DiscoveryCache::forDirectories($directories, $storage->cacheDirectory),
        );
    }

    private function sharded(ExecutionPlan $plan, TestSelection $selection): ExecutionPlan
    {
        if ($selection->shard === null) {
            return $plan;
        }

        [$index, $count] = $selection->shard;

        return PlanShard::select($plan, \max(1, $index), \max(1, $count));
    }
}
