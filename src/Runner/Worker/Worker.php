<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;

/**
 * Executes a plan slice sequentially in the current process, managing the
 * class and test scopes and emitting events as results happen. A class-scope
 * teardown failure is attributed to the test that triggered the close: the
 * last test executed in that class.
 *
 * @internal
 */
final readonly class Worker
{
    public function __construct(
        private HarnessRegistry $registry,
    ) {}

    public function run(ExecutionPlan $plan, EventSink $sink, ?int $stopAfterFailures = null): ResultSummary
    {
        $scopes = new HarnessScopes($this->registry);
        $summary = new ResultSummary();
        $stopped = false;

        foreach ($plan->entriesByClass() as $class => $entries) {
            if ($stopped) {
                break;
            }

            $sink->emit(new TestClassStarted($class, \microtime(true)));
            $scopes->openClass();
            $lastIndex = \count($entries) - 1;

            $context = null;
            $executor = null;

            foreach ($entries as $index => $entry) {
                $sink->emit(new TestStarted($entry->id, \microtime(true)));

                try {
                    $context ??= ClassContext::for($class);
                    $executor ??= new TestExecutor($scopes, $context);
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
                $sink->emit(new TestFinished($result, \microtime(true)));

                if ($stopAfterFailures !== null && $summary->failed + $summary->errored >= $stopAfterFailures) {
                    $stopped = true;

                    if ($index !== $lastIndex) {
                        $scopes->closeClass();
                    }

                    break;
                }
            }

            $sink->emit(new TestClassFinished($class, \microtime(true)));
        }

        $scopes->closeRun();

        return $summary;
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
