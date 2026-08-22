<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Config\Configuration;
use Greenlight\Config\StorageLayout;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Wire\WireCommunicationFailed;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Execution\ExecutionAdapter;
use Greenlight\Runner\Execution\ExecutionContext;
use Greenlight\Runner\Integration\IntegrationFixtureError;
use Greenlight\Runner\Integration\IntegrationFixtureManager;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\EventSink;

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
     * @throws ProtocolError
     * @throws ReportGenerationFailed
     * @throws WireCommunicationFailed
     */
    public function run(
        Configuration $configuration,
        array $directories,
        EventSink $sink,
        ExecutionAdapter $execution,
        array $priorityClasses = [],
        array $classSeconds = [],
    ): RunResult {
        $seed = $configuration->randomizeOrder
            ? $configuration->randomSeed ?? \random_int(0, 2 ** 31 - 1)
            : null;
        $classSeconds = $configuration->randomizeOrder ? [] : $classSeconds;
        $storage = StorageLayout::resolve($configuration->storage, $this->workingDirectory);
        $plan = PlanOrder::schedule(
            $this->sharded($this->discover($configuration, $directories, $seed, $storage), $configuration),
            $priorityClasses,
            $classSeconds,
        );
        $topology = $execution->topology($plan, $configuration, $classSeconds);
        $runId = \bin2hex(\random_bytes(8));
        $startedAt = \hrtime(true);
        $artifacts = ArtifactStore::open(
            $configuration->artifacts,
            $this->workingDirectory,
            $runId,
            temporaryDirectory: $storage->temporaryDirectory,
        );

        try {
            $orchestratorPlugins = PluginInstances::forOrchestrator($configuration->plugins);

            if ($orchestratorPlugins->runSubscribers() !== []) {
                $sink = new PluginEventSink($orchestratorPlugins, $sink);
            }

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

                return new RunResult($summary, 0, $durationSeconds, $seed);
            }

            $fixtures = IntegrationFixtureManager::provision(
                $orchestratorPlugins,
                $runId,
                $topology->workers,
                $topology->fixtureChannels,
                $configuration->shard,
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
                        $configuration,
                        $artifacts,
                        $fixtures,
                        $orchestratorPlugins,
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
                $result = new RunResult(
                    $outcome->summary,
                    \count($plan),
                    $durationSeconds,
                    $seed,
                    $outcome->coverage,
                    $outcome->leaks,
                );
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

            return $result;
        } finally {
            $artifacts->cleanup();
        }
    }

    /**
     * @param list<non-empty-string> $directories
     * @throws DiscoveryError
     */
    private function discover(
        Configuration $configuration,
        array $directories,
        ?int $seed,
        StorageLayout $storage,
    ): ExecutionPlan {
        return new TestDiscoverer()->discover(
            $directories,
            SelectionFilter::fromConfiguration($configuration),
            $seed,
            DiscoveryCache::forDirectories($directories, $storage->cacheDirectory),
        );
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
