<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Config\Configuration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\EnvironmentBackup;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\PublishingEventSink;
use Greenlight\Runner\Integration\IntegrationFixtureError;
use Greenlight\Runner\Integration\IntegrationFixtureManager;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Runner\Worker\LeakDetector;
use Greenlight\Runner\Worker\Worker;

/**
 * Discovers and executes a plan in the orchestrator process.
 *
 * @internal
 */
final readonly class InProcessRunner
{
    public function __construct(
        private string $workingDirectory,
    ) {}

    /**
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $priorityClasses Classes that run first in the specified order.
     * @param array<string, float> $classSeconds Recorded class durations for longest-first ordering. Seeded runs ignore this value.
     *
     * @throws DiscoveryError
     * @throws AttachmentError
     * @throws IntegrationFixtureError
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
        $artifactConfiguration = $configuration->artifacts;
        $artifactStore = ArtifactStore::open(
            $artifactConfiguration,
            $this->workingDirectory,
            $runId,
        );
        $channelEnvironment = null;

        try {
            $plugins = PluginRegistry::forWorker($configuration->plugins);
            $orchestratorSide = PluginRegistry::orchestratorSide($configuration->plugins);

            if ($orchestratorSide->runSubscribers() !== []) {
                $sink = new PluginEventSink($orchestratorSide, $sink);
            }

            $sink = new PublishingEventSink($artifactStore, $sink);

            if (\count($plan) === 0) {
                $sink->emit(new RunStarted($runId, 0, 1, \microtime(true), $artifactStore->publicDirectory()));
                $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
                $summary = new ResultSummary();
                $sink->emit(new RunFinished($runId, $summary, $durationSeconds, \microtime(true)));

                return new RunResult($summary, 0, $durationSeconds, $seed);
            }

            $fixtures = IntegrationFixtureManager::provision($orchestratorSide, $runId, 1, 1, $configuration->shard);
            $collector = null;
            $collectingCoverage = false;

            try {
                $sink->emit(new RunStarted(
                    $runId,
                    \count($plan),
                    1,
                    \microtime(true),
                    $artifactStore->publicDirectory(),
                ));

                // A single in-process worker always uses channel 1. Set the
                // variable to replace a value inherited from an outer Greenlight
                // run.
                $channelEnvironment = EnvironmentBackup::capture('GREENLIGHT_CHANNEL');
                \putenv('GREENLIGHT_CHANNEL=1');

                $resources = $fixtures->forChannel(1);

                try {
                    $plugins->bootstrapWorker(new WorkerBootstrapContext(
                        'in-process',
                        new TestChannel(1),
                        $resources,
                    ));
                } catch (\Throwable $failure) {
                    $detail = ThrowableDetail::fromThrowable($failure);

                    throw ProtocolError::workerFatal(
                        'in-process',
                        $detail->message,
                        $detail->file,
                        $detail->line,
                        $failure,
                    );
                }

                $collector = $coverageSettings instanceof CoverageSettings ? CoverageCollector::create($coverageSettings) : null;
                $collectingCoverage = $collector instanceof CoverageCollector;
                $collector?->start();

                $outcome = $plugins->runWorker(static fn() => new Worker(
                    DefaultServices::registry($plugins, $resources),
                    $plugins,
                    $detectLeaks ? new LeakDetector() : null,
                    'in-process',
                    $configuration->policy->isNoOp() ? null : $configuration->policy,
                    $artifactStore,
                )->run(
                    $plan,
                    $sink,
                    $configuration->stopAfterFailures,
                    null,
                    $shutdown instanceof GracefulShutdown ? $shutdown->requested(...) : null,
                ));
                $summary = $outcome->summary;

                $collectingCoverage = false;
                $coverage = $collector?->stop();

                $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
                $sink->emit(new RunFinished($runId, $summary, $durationSeconds, \microtime(true)));

                $result = new RunResult($summary, \count($plan), $durationSeconds, $seed, $coverage, $outcome->leaks);
            } catch (\Throwable $failure) {
                if ($collectingCoverage) {
                    try {
                        $collector?->stop();
                    } catch (\Throwable) {
                        // Preserve the failure that aborted the run.
                    }
                }

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
            $channelEnvironment?->restore();
            $artifactStore->cleanup();
        }
    }

    /**
     * @param list<non-empty-string> $directories
     * @throws DiscoveryError
     */
    private function discover(Configuration $configuration, array $directories, ?int $seed): ExecutionPlan
    {
        $filter = SelectionFilter::fromConfiguration($configuration);

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
