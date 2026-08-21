<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Artifact\ArtifactStore;

/**
 * Greenlight assigns class-scope disposal failures to the last executed test
 * in that class. This test causes the class scope to close.
 *
 * run() stops early in these conditions:
 *
 * - The run reaches the failure limit.
 * - The worker uses its replacement budget.
 * - The orchestrator requests a drain between tests.
 *
 * Greenlight checks the replacement budget after each test. run() reports
 * incomplete entries in plan order.
 *
 * @internal
 */
final readonly class Worker
{
    public function __construct(
        private HarnessRegistry $registry,
        private PluginRegistry $plugins = new PluginRegistry([]),
        private ?LeakDetector $leakDetector = null,
        private string $workerId = '',
        private ?ResultPolicy $policy = null,
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
        ?WorkerBudget $budget = null,
        ?\Closure $drainRequested = null,
        ?HarnessScopes $scopes = null,
        ?\Closure $attemptStarted = null,
    ): WorkerRunOutcome {
        // This call does not close externally owned scopes. Thus, run services
        // remain available when one worker runs multiple assignments. The
        // owner closes the run scope at exit.
        $ownScopes = !$scopes instanceof HarnessScopes;
        $scopes ??= new HarnessScopes($this->registry, $this->plugins->serviceResolvers());
        $summary = new ResultSummary();
        $executed = 0;
        $recycleReason = null;
        $drained = false;
        $stopped = false;
        $remaining = [];
        $leaks = [];

        foreach ($plan->entriesByClass() as $class => $entries) {
            if ($stopped) {
                $remaining = [...$remaining, ...\array_map(static fn(PlanEntry $entry): TestId => $entry->id, $entries)];

                continue;
            }

            $isolated = \count($entries) === 1 && $entries[0]->metadata->isolated;
            $sink->emit(new TestClassStarted($class, \microtime(true), $this->workerId, $isolated));
            $scopes->openClass(allowPerClassServices: !$entries[0]->metadata->allowParallel);
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
                        $this->plugins,
                        $this->leakDetector,
                        $this->policy,
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

                ++$executed;
                $candidateSummary = $summary->add($result->outcome);
                $failureLimitReached = $stopAfterFailures !== null
                    && $candidateSummary->failed + $candidateSummary->errored >= $stopAfterFailures;
                $countLimitReached = $budget instanceof WorkerBudget && $budget->exhaustedByCount($executed);
                $memoryLimitReached = $budget instanceof WorkerBudget && $budget->exhaustedByMemory();
                $drainReached = $drainRequested instanceof \Closure && $drainRequested();

                if ($index === $lastIndex || $failureLimitReached || $countLimitReached || $memoryLimitReached || $drainReached) {
                    $result = HarnessServiceDisposal::applyToTest($result, $scopes->closeClass());
                }

                $summary = $summary->add($result->outcome);
                $sink->emit(new TestFinished($result, \microtime(true)));

                if ($this->leakDetector instanceof LeakDetector) {
                    $leaks = [...$leaks, ...$this->leakDetector->sweep()];
                }

                $stopReached = match (true) {
                    $stopAfterFailures !== null && $summary->failed + $summary->errored >= $stopAfterFailures => 'bail',
                    $countLimitReached => 'count',
                    $memoryLimitReached => 'memory',
                    $drainReached => 'drain',
                    default => null,
                };

                if ($stopReached !== null) {
                    $stopped = true;
                    $recycleReason = match ($stopReached) {
                        'count' => RecycleReason::TestCount,
                        'memory' => RecycleReason::Memory,
                        default => null,
                    };
                    $drained = $stopReached === 'drain' || $stopReached === 'bail';

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

        $outcome = new WorkerRunOutcome($summary, $remaining, $recycleReason, $drained, $leaks);

        if (!$ownScopes) {
            return $outcome;
        }

        return HarnessServiceDisposal::runAndClose($scopes, static fn(): WorkerRunOutcome => $outcome);
    }
}
