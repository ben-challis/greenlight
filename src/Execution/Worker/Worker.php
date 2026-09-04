<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\EventSink;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;

/**
 * Greenlight assigns class-scope disposal failures to the last executed test
 * in that class. This test causes the class scope to close.
 *
 * run() stops early in these conditions:
 *
 * - The run reaches the failed-or-errored test limit.
 * - The orchestrator requests a drain between tests.
 *
 * run() reports incomplete entries in plan order.
 *
 * @internal
 */
final readonly class Worker
{
    /**
     * @param list<ServiceDefinition> $definitions
     */
    public function __construct(
        private array $definitions,
        private ?WorkerPluginRuntime $plugins = null,
        private ?LeakDetector $leakDetector = null,
        private string $workerId = '',
        private ?ArtifactStore $artifactStore = null,
    ) {}

    /**
     * @param \Closure(): bool|null $drainRequested polled between tests
     * @param \Closure(TestId, positive-int): void|null $attemptStarted reports retry progress for crash containment
     *
     * @throws WorkerError
     */
    public function run(
        ExecutionPlan $plan,
        EventSink $sink,
        ?int $stopAfterFailures = null,
        ?\Closure $drainRequested = null,
        ?HarnessScopes $scopes = null,
        ?\Closure $attemptStarted = null,
    ): WorkerRunOutcome {
        // This call does not close externally owned scopes. Thus, run services
        // remain available when one worker runs multiple assignments. The
        // owner closes the worker scope at exit.
        $ownScopes = !$scopes instanceof HarnessScopes;
        $plugins = $this->plugins ?? WorkerPluginRuntime::fromDefinitions([]);
        $scopes ??= new HarnessScopes($this->definitions);
        $summary = new ResultSummary();
        $drained = false;
        $stopped = false;
        $remaining = [];
        $leaks = [];

        try {
            foreach ($plan->entriesByClass() as $class => $entries) {
                if ($stopped) {
                    $remaining = [...$remaining, ...\array_map(static fn(PlanEntry $entry): TestId => $entry->id, $entries)];

                    continue;
                }

                $isolated = \count($entries) === 1 && $entries[0]->definition->scheduling->isolated;
                $sink->emit(new TestClassStarted($class, \microtime(true), $this->workerId, $isolated));
                $scopes->openClass(allowPerClassServices: !$entries[0]->definition->scheduling->allowParallel);
                $lastIndex = \count($entries) - 1;

                $context = null;
                $executor = null;

                foreach ($entries as $index => $entry) {
                    $sink->emit(new TestStarted($entry->id, \microtime(true)));

                    try {
                        $context ??= ClassContext::for($class);
                        $executor ??= new TestExecutor(
                            $scopes,
                            $context,
                            $plugins,
                            $this->leakDetector,
                            $this->artifactStore,
                            $attemptStarted,
                        );
                        $result = $executor->execute($entry);
                    } catch (\Throwable $threw) {
                        $result = new TestResult(
                            $entry->id,
                            Outcome::Errored,
                            0.0,
                            0,
                            error: ThrowableDetail::fromThrowable($threw),
                        );
                    }

                    $result = $plugins->terminalResult($entry->definition, $result);

                    $candidateSummary = $summary->add($result->outcome);
                    $failureLimitReached = $stopAfterFailures !== null
                        && $candidateSummary->failed + $candidateSummary->errored >= $stopAfterFailures;
                    $drainReached = $drainRequested instanceof \Closure && $drainRequested();

                    if ($index === $lastIndex || $failureLimitReached || $drainReached) {
                        $result = HarnessServiceDisposal::applyToTest($result, $scopes->closeClass());
                    }

                    $summary = $summary->add($result->outcome);
                    $sink->emit(new TestFinished($result, \microtime(true)));

                    if ($this->leakDetector instanceof LeakDetector) {
                        $leaks = [...$leaks, ...$this->leakDetector->sweep()];
                    }

                    $stopReached = match (true) {
                        $stopAfterFailures !== null && $summary->failed + $summary->errored >= $stopAfterFailures => 'bail',
                        $drainReached => 'drain',
                        default => null,
                    };

                    if ($stopReached !== null) {
                        $stopped = true;
                        $drained = true;

                        if ($index !== $lastIndex) {
                            $remaining = \array_map(
                                static fn(PlanEntry $unexecuted): TestId => $unexecuted->id,
                                \array_slice($entries, $index + 1),
                            );
                        }

                        break;
                    }
                }

                $sink->emit(new TestClassFinished($class, \microtime(true), $this->workerId));
            }
        } catch (\Throwable $primary) {
            $failures = $scopes->closeClass();

            if ($ownScopes) {
                $failures = [...$failures, ...$scopes->closeWorker()];
            }

            if ($failures !== []) {
                throw WorkerError::afterHarnessServiceDisposal($primary, $failures);
            }

            throw $primary;
        }

        $outcome = new WorkerRunOutcome($summary, $remaining, $drained, $leaks);

        if (!$ownScopes) {
            return $outcome;
        }

        return HarnessServiceDisposal::runAndClose($scopes, static fn(): WorkerRunOutcome => $outcome);
    }
}
