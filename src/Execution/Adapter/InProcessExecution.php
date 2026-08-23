<?php

declare(strict_types=1);

namespace Greenlight\Execution\Adapter;

use Greenlight\Coverage\Collection\CoverageCollector;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Event\EventSink;
use Greenlight\Execution\Artifact\PublishingEventSink;
use Greenlight\Execution\ExecutionAdapter;
use Greenlight\Execution\ExecutionContext;
use Greenlight\Execution\ExecutionFailed;
use Greenlight\Execution\ExecutionOutcome;
use Greenlight\Execution\ExecutionTopology;
use Greenlight\Execution\Plugin\PluginInstances;
use Greenlight\Execution\Worker\DefaultServices;
use Greenlight\Execution\Worker\LeakDetector;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Execution\Worker\WorkerError;
use Greenlight\Internal\Process\EnvironmentBackup;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestChannel;

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
        array $classSeconds,
    ): ExecutionTopology {
        return new ExecutionTopology(1, 1);
    }

    /** @throws ExecutionFailed */
    #[\Override]
    public function execute(
        ExecutionPlan $plan,
        EventSink $sink,
        ExecutionContext $context,
    ): ExecutionOutcome {
        $execution = $context->execution;
        $plugins = PluginInstances::forWorker($execution->plugins);
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

                throw ExecutionFailed::workerFatal(
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
                        DefaultServices::definitions(
                            $plugins,
                            $resources,
                            $context->storage->generatedCodeDirectory,
                            $context->storage->temporaryDirectory,
                        ),
                        $plugins,
                        $this->detectLeaks ? new LeakDetector() : null,
                        'in-process',
                        $execution->policy->isNoOp() ? null : $execution->policy,
                        $context->artifacts,
                    )->run(
                        $plan,
                        new PublishingEventSink($context->artifacts, $sink),
                        $execution->stopAfterFailures,
                        $this->shutdown instanceof GracefulShutdown ? $this->shutdown->requested(...) : null,
                    ));
                } catch (WorkerError $failure) {
                    $detail = ThrowableDetail::fromThrowable($failure);

                    throw ExecutionFailed::workerFatal(
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
