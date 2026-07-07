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

/**
 * Executes a plan slice sequentially in the current process.
 *
 * run() manages the class and test scopes and emits events as results
 * happen. A class-scope teardown failure is attributed to the test that
 * triggered the close: the last test executed in that class.
 *
 * run() stops early when the failure threshold is hit, when the recycling
 * budget is exhausted (checked after each test), or when a drain is
 * requested between tests. Unexecuted entries are reported back in plan
 * order.
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
    ) {}

    /**
     * @param \Closure(): bool|null $drainRequested polled between tests
     */
    public function run(
        ExecutionPlan $plan,
        EventSink $sink,
        ?int $stopAfterFailures = null,
        ?WorkerBudget $budget = null,
        ?\Closure $drainRequested = null,
        ?HarnessScopes $scopes = null,
    ): WorkerRunOutcome {
        // Externally owned scopes survive this call, so per-run services
        // keep worker-lifetime semantics when one worker runs several
        // assignments; the owner closes the run scope on exit.
        $ownScopes = !$scopes instanceof HarnessScopes;
        $scopes ??= new HarnessScopes($this->registry);
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

            $sink->emit(new TestClassStarted($class, \microtime(true), $this->workerId));
            $scopes->openClass();
            $lastIndex = \count($entries) - 1;

            $context = null;
            $executor = null;

            foreach ($entries as $index => $entry) {
                $sink->emit(new TestStarted($entry->id, \microtime(true)));

                try {
                    $context ??= ClassContext::for($class);
                    $executor ??= new TestExecutor($scopes, $context, $this->plugins, $this->leakDetector, $this->policy);
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

                if ($index === $lastIndex) {
                    $result = $this->applyScopeTeardown($result, $scopes->closeClass());
                }

                $summary = $summary->add($result->outcome);
                ++$executed;
                $sink->emit(new TestFinished($result, \microtime(true)));

                if ($this->leakDetector instanceof LeakDetector) {
                    $leaks = [...$leaks, ...$this->leakDetector->sweep()];
                }

                $stopReached = match (true) {
                    $stopAfterFailures !== null && $summary->failed + $summary->errored >= $stopAfterFailures => 'bail',
                    $budget instanceof WorkerBudget && $budget->exhaustedByCount($executed) => 'count',
                    $budget instanceof WorkerBudget && $budget->exhaustedByMemory() => 'memory',
                    $drainRequested instanceof \Closure && $drainRequested() => 'drain',
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
                        $scopes->closeClass();
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

        if ($ownScopes) {
            $scopes->closeRun();
        }

        return new WorkerRunOutcome($summary, $remaining, $recycleReason, $drained, $leaks);
    }

    /**
     * @param list<\Throwable> $teardownFailures
     */
    private function applyScopeTeardown(TestResult $result, array $teardownFailures): TestResult
    {
        if ($teardownFailures === [] || !$result->outcome->isSuccessful()) {
            return $result;
        }

        return new TestResult(
            $result->id,
            Outcome::Errored,
            $result->durationSeconds,
            $result->memoryDeltaBytes,
            $result->attempts,
            $result->failures,
            ThrowableDetail::fromThrowable($teardownFailures[0]),
            $result->skipReason,
            $result->transformations,
        );
    }
}
