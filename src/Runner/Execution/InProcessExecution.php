<?php

declare(strict_types=1);

namespace Greenlight\Runner\Execution;

use Greenlight\Config\Configuration;
use Greenlight\Core\EnvironmentBackup;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Runner\Artifact\PublishingEventSink;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\PluginInstances;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Runner\Worker\LeakDetector;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Runner\Worker\WorkerError;

/**
 * Executes a plan in the orchestrator process.
 *
 * @internal
 */
final readonly class InProcessExecution implements ExecutionAdapter
{
    public function __construct(
        private ?CoverageSettings $coverageSettings = null,
        private bool $detectLeaks = false,
        private ?GracefulShutdown $shutdown = null,
    ) {}

    #[\Override]
    public function topology(
        ExecutionPlan $plan,
        Configuration $configuration,
        array $classSeconds,
    ): ExecutionTopology {
        return new ExecutionTopology(1, 1);
    }

    /** @throws ProtocolError */
    #[\Override]
    public function execute(
        ExecutionPlan $plan,
        EventSink $sink,
        ExecutionContext $context,
    ): ExecutionOutcome {
        $configuration = $context->configuration;
        $plugins = PluginInstances::forWorker($configuration->plugins);
        $resources = $context->fixtures->forChannel(1);
        $channelEnvironment = EnvironmentBackup::capture('GREENLIGHT_CHANNEL');
        \putenv('GREENLIGHT_CHANNEL=1');

        try {
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

            $collector = $this->coverageSettings instanceof CoverageSettings
                ? CoverageCollector::create($this->coverageSettings)
                : null;
            $collectingCoverage = $collector instanceof CoverageCollector;
            $collector?->start();

            try {
                try {
                    $outcome = $plugins->runWorker(fn() => new Worker(
                        DefaultServices::registry(
                            $plugins,
                            $resources,
                            $context->storage->generatedCodeDirectory,
                            $context->storage->temporaryDirectory,
                        ),
                        $plugins,
                        $this->detectLeaks ? new LeakDetector() : null,
                        'in-process',
                        $configuration->policy->isNoOp() ? null : $configuration->policy,
                        $context->artifacts,
                    )->run(
                        $plan,
                        new PublishingEventSink($context->artifacts, $sink),
                        $configuration->stopAfterFailures,
                        null,
                        $this->shutdown instanceof GracefulShutdown ? $this->shutdown->requested(...) : null,
                    ));
                } catch (WorkerError $failure) {
                    $detail = ThrowableDetail::fromThrowable($failure);

                    throw ProtocolError::workerFatal(
                        'in-process',
                        $detail->message,
                        $detail->file,
                        $detail->line,
                        $failure,
                    );
                }

                $collectingCoverage = false;
                $coverage = $collector?->stop();
            } catch (\Throwable $failure) {
                if ($collectingCoverage) {
                    try {
                        $collector?->stop();
                    } catch (\Throwable) {
                        // Preserve the failure that stopped execution.
                    }
                }

                throw $failure;
            }

            return new ExecutionOutcome($outcome->summary, $coverage, $outcome->leaks);
        } finally {
            $channelEnvironment->restore();
        }
    }
}
