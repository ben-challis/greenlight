<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\Attachments;
use Greenlight\Artifact\UnavailableAttachments;
use Greenlight\Condition\Condition;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\StagedAttachments;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\Cleanup;
use Greenlight\Test\CleanupFailed;
use Greenlight\Test\ExpectationCounter;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestId;

/**
 * Each test attempt can have these operations, in this order:
 *
 * - Data-set expansion
 * - Constructor injection
 * - `beforeTest()` subscribers
 * - `Before` hooks
 * - The test method
 * - `After` hooks
 * - Cleanup callbacks
 * - Test-scope teardown
 * - Timeout enforcement
 * - `afterTest()` subscribers
 * - Attempt-end subscribers
 *
 * Data-set expansion occurs before the timed window starts.
 *
 * `Before` hooks use declaration order. `After` hooks use reverse declaration
 * order.
 *
 * Output capture includes cleanup callbacks and test-scope teardown.
 *
 * An `After` hook runs if constructor injection created a test instance. It
 * runs even when a previous test-body operation did not complete.
 * The `afterTest()` subscribers run only if the test instance exists.
 * Attempt-end subscribers run for each attempt.
 *
 * The plugin runtime preserves the test identity when it applies `afterTest()`
 * results. It validates each outcome change against the transformation log.
 * The executor releases its test-instance references when the attempt ends.
 *
 * @internal
 */
final readonly class TestExecutor
{
    /**
     * @param \Closure(TestId, positive-int): void|null $attemptStarted
     */
    public function __construct(
        private HarnessScopes $scopes,
        private ClassContext $context,
        private WorkerPluginRuntime $plugins,
        private ?LeakDetector $leakDetector = null,
        private ?ArtifactStore $artifactStore = null,
        private ?\Closure $attemptStarted = null,
    ) {}

    /**
     * @throws CaptureError
     * @throws AttachmentError
     */
    public function execute(PlanEntry $entry): TestResult
    {
        $definition = $entry->definition;
        $skip = $definition->skip;

        if ($skip->reason !== null) {
            return $this->skipped($entry, $skip->reason);
        }

        if ($skip->condition !== null) {
            $satisfied = $this->evaluateCondition($skip->condition, $skip->arguments);

            if ($satisfied instanceof \Throwable) {
                return new TestResult(
                    $entry->id,
                    Outcome::Errored,
                    0.0,
                    0,
                    error: ThrowableDetail::fromThrowable($satisfied),
                );
            }

            if (!$satisfied) {
                return $this->skipped($entry, \sprintf(
                    'Condition %s is not satisfied',
                    $this->describeCondition($skip->condition, $skip->arguments),
                ));
            }
        }

        $attempt = 0;
        $retainedAttachments = [];
        $artifactBudget = new TestArtifactBudget();

        do {
            ++$attempt;

            if ($this->attemptStarted instanceof \Closure) {
                ($this->attemptStarted)($entry->id, $attempt);
            }

            try {
                [$result, $cause, $attachments] = $this->plugins->runTestAttempt(
                    fn(): array => $this->attempt($entry, $attempt, $artifactBudget),
                );
            } catch (\Throwable $threw) {
                $cause = $threw;
                $attachments = null;
                $result = new TestResult(
                    $entry->id,
                    Outcome::Errored,
                    0.0,
                    0,
                    $attempt,
                    error: ThrowableDetail::fromThrowable($threw),
                );
            }

            if ($attachments instanceof StagedAttachments) {
                $result = $result->withAttachments($attachments->collected());
            }

            $retry = false;

            if (!$result->outcome->isSuccessful()) {
                try {
                    $retry = $this->plugins->shouldRetry($definition->retry, $result, $attempt, $cause);
                } catch (\Throwable $threw) {
                    $result = $result->erroredBy(ThrowableDetail::fromThrowable($threw));
                }
            }

            $sealed = $attachments?->seal() ?? [];
            $retainedAttachments = [...$retainedAttachments, ...$sealed];

            if (!$retry) {
                return $result->withAttachments($retainedAttachments);
            }
        } while (true);
    }

    /**
     * Runs one attempt and then the attempt-end subscribers. The subscribers
     * run for each attempt, also when the attempt fails before Greenlight
     * creates the test instance.
     *
     * @return array{TestResult, ?\Throwable, ?StagedAttachments}
     */
    private function attempt(PlanEntry $entry, int $attempt, TestArtifactBudget $artifactBudget): array
    {
        try {
            [$result, $cause, $attachments] = $this->runAttempt($entry, $attempt, $artifactBudget);
        } catch (\Throwable $threw) {
            $cause = $threw;
            $attachments = null;
            $result = new TestResult(
                $entry->id,
                Outcome::Errored,
                0.0,
                0,
                $attempt,
                error: ThrowableDetail::fromThrowable($threw),
            );
        }

        $result = $this->plugins->testAttemptEnded($entry->id, $entry->definition, $result);

        return [$result, $cause, $attachments];
    }

    /**
     * @return array{TestResult, ?\Throwable, ?StagedAttachments}
     * @throws CaptureError
     * @throws AttachmentError
     */
    private function runAttempt(PlanEntry $entry, int $attempt, TestArtifactBudget $artifactBudget): array
    {
        $execution = $entry->definition->execution;
        ExpectationCounter::reset();
        $this->scopes->openTest();

        $capture = $execution->capture ? new OutputCapture() : null;
        $stagedAttachments = $this->artifactStore?->forAttempt($entry->id, $attempt, $artifactBudget);
        $cleanup = new Cleanup();
        $capture?->start();
        $run = new AttemptRun(
            $entry,
            $stagedAttachments ?? new UnavailableAttachments(),
            $cleanup,
            $capture,
            $this->expandDataSets($entry),
        );
        $memoryBefore = \memory_get_usage(true);
        $startedAt = \hrtime(true);
        ExpectationRuntime::enterAttempt(
            $execution->timeoutSeconds === null
                ? null
                : $startedAt / 1_000_000_000 + $execution->timeoutSeconds,
        );

        $this->runPhases($run);

        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $memoryDelta = \memory_get_usage(true) - $memoryBefore;
        $result = $this->result($run, $attempt, $durationSeconds, $memoryDelta);
        $cause = $run->cause;

        if (!$cause instanceof \Throwable && $run->disposalFailures !== []) {
            $cause = $run->disposalFailures[0];
        }

        if ($run->context instanceof TestContext) {
            $result = $this->plugins->afterTest($run->context, $result);
            $this->leakDetector?->watch($entry->id, $run->context->instance);
        }

        return [$result, $cause, $stagedAttachments];
    }

    /**
     * Runs each phase in `AttemptPhase` order. A test-body phase runs only
     * while the attempt is not interrupted. Each teardown phase runs. The last
     * teardown throwable propagates after all phases, as with nested
     * `finally` blocks.
     *
     * @throws CaptureError
     */
    private function runPhases(AttemptRun $run): void
    {
        $teardownFailure = null;

        foreach (AttemptPhase::cases() as $phase) {
            if (!$phase->alwaysRuns()) {
                if ($run->interrupted()) {
                    continue;
                }

                try {
                    $this->runPhase($phase, $run);
                } catch (\Throwable $threw) {
                    $run->errored($threw);
                }

                continue;
            }

            try {
                $this->runPhase($phase, $run);
            } catch (\Throwable $threw) {
                $teardownFailure = $threw;
            }
        }

        if ($teardownFailure instanceof \Throwable) {
            throw $teardownFailure;
        }
    }

    /**
     * @throws \Throwable
     */
    private function runPhase(AttemptPhase $phase, AttemptRun $run): void
    {
        match ($phase) {
            AttemptPhase::Instantiate => $this->instantiatePhase($run),
            AttemptPhase::BeforeTestSubscribers => $this->beforeTestPhase($run),
            AttemptPhase::TestMethod => $this->testMethodPhase($run),
            AttemptPhase::AfterHooks => $this->afterHooksPhase($run),
            AttemptPhase::CleanupCallbacks => $this->cleanupPhase($run),
            AttemptPhase::CloseTestScope => $run->disposalFailures = $this->scopes->closeTest(),
            AttemptPhase::LeaveExpectationWindow => ExpectationRuntime::leaveAttempt(),
            AttemptPhase::StopCapture => $run->output = $run->capture?->stop(),
        };
    }

    /**
     * @throws UnresolvableService
     * @throws ServiceResolutionFailed
     */
    private function instantiatePhase(AttemptRun $run): void
    {
        $definition = $run->entry->definition;
        $instance = $this->instantiate($definition->class, $run->attachments, $run->cleanup);
        $run->context = new TestContext($instance, $run->entry->id, $definition, $this->scopes, $run->attachments);
    }

    private function beforeTestPhase(AttemptRun $run): void
    {
        if (!$run->context instanceof TestContext) {
            return;
        }

        try {
            $this->plugins->beforeTest($run->context);
        } catch (SkipTest $skip) {
            $run->skipped($skip->reason);
        } catch (PluginRuntimeError $failure) {
            $run->errored($failure);
        }
    }

    private function testMethodPhase(AttemptRun $run): void
    {
        if (!$run->context instanceof TestContext) {
            return;
        }

        $instance = $run->context->instance;
        $entry = $run->entry;
        $definition = $entry->definition;

        try {
            foreach ($this->context->beforeHooks as $hook) {
                $hook->invoke($instance);
            }

            if ($run->dataSetFailure instanceof \Throwable) {
                throw $run->dataSetFailure;
            }

            $arguments = [];

            if ($entry->id->dataSetKey !== null) {
                $arguments = $this->context->argumentsFor(
                    $definition->dataProvider->method,
                    $definition->dataProvider->class,
                    $definition->method,
                    $entry->id->dataSetKey,
                );
            }

            $this->context->reflection->getMethod($definition->method)->invokeArgs($instance, $arguments);
        } catch (SkipTest $skip) {
            $run->skipped($skip->reason);
        } catch (\Throwable $threw) {
            $run->failed($threw);
        }
    }

    /**
     * `After` hooks run only if the test instance exists. Each hook runs, also
     * when an earlier hook throws.
     */
    private function afterHooksPhase(AttemptRun $run): void
    {
        if (!$run->context instanceof TestContext) {
            return;
        }

        foreach ($this->context->afterHooks as $hook) {
            try {
                $hook->invoke($run->context->instance);
            } catch (\Throwable $threw) {
                $run->teardownFailed($threw);
            }
        }
    }

    private function cleanupPhase(AttemptRun $run): void
    {
        try {
            $run->cleanup->close();
        } catch (CleanupFailed $cleanupFailed) {
            foreach ($cleanupFailed->failures as $cleanupFailure) {
                if (!$run->teardownFailed($cleanupFailure)) {
                    $run->secondaryCleanupFailure($cleanupFailure);
                }
            }
        }
    }

    private function result(AttemptRun $run, int $attempt, float $durationSeconds, int $memoryDelta): TestResult
    {
        $execution = $run->entry->definition->execution;
        $outcome = $run->outcome();
        $failures = $run->failures;
        $budget = $execution->timeoutSeconds;

        if ($budget !== null && $durationSeconds > $budget && $outcome === Outcome::Passed) {
            $outcome = Outcome::Failed;
            $failures = [new FailureDetail(\sprintf(
                'The configured time limit is %.3f seconds. The test took %.3f seconds.',
                $budget,
                $durationSeconds,
            ))];
        }

        $result = new TestResult(
            $run->entry->id,
            $outcome,
            \max(0.0, $durationSeconds),
            $memoryDelta,
            $attempt,
            $failures,
            $run->error,
            $run->skipReason,
            output: $run->output,
            expectations: ExpectationCounter::count(),
        );

        $result = HarnessServiceDisposal::applyToTest($result, $run->disposalFailures);

        // The counter includes double verification from scope close. A passed
        // test with no verified expectations is a risky test unless it has
        // #[NoExpectations].
        if ($result->outcome === Outcome::Passed && !$execution->noExpectations && $result->expectations === 0) {
            $result = $result->asRisky();
        }

        return $result;
    }

    /**
     * Expands the data sets before the timed window starts. A failure becomes
     * the test error after the `Before` hooks.
     */
    private function expandDataSets(PlanEntry $entry): ?\Throwable
    {
        if ($entry->id->dataSetKey === null) {
            return null;
        }

        $definition = $entry->definition;

        try {
            $this->context->expand(
                $definition->dataProvider->method,
                $definition->dataProvider->class,
                $definition->method,
            );
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }

    /**
     * @param non-empty-string $class
     * @throws UnresolvableService
     * @throws ServiceResolutionFailed
     */
    private function instantiate(string $class, Attachments $attachments, Cleanup $cleanup): object
    {
        $constructor = $this->context->reflection->getConstructor();
        $arguments = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }

                throw UnresolvableService::unsupportedParameter($parameter->getName(), $class);
            }

            /** @var class-string $serviceType */
            $serviceType = $type->getName();

            $attributes = \array_map(
                static fn(\ReflectionAttribute $attribute): object => $attribute->newInstance(),
                $parameter->getAttributes(),
            );
            $hasSource = \array_any(
                $attributes,
                static fn(object $attribute): bool => $attribute instanceof Service && $attribute->source !== null,
            );

            if ($serviceType === Attachments::class && !$hasSource) {
                $arguments[] = $attachments;

                continue;
            }

            if ($serviceType === Cleanup::class && !$hasSource) {
                $arguments[] = $cleanup;

                continue;
            }

            $arguments[] = $this->scopes->resolve($serviceType, $class, $attributes);
        }

        return $this->context->reflection->newInstanceArgs($arguments);
    }

    /**
     * @param non-empty-string $conditionClass
     * @param list<scalar|null> $arguments
     */
    private function evaluateCondition(string $conditionClass, array $arguments): bool|\Throwable
    {
        try {
            if (!\class_exists($conditionClass)) {
                return WorkerError::conditionClassMissing($conditionClass);
            }

            $condition = new $conditionClass(...$arguments);

            if (!$condition instanceof Condition) {
                return WorkerError::invalidConditionClass($conditionClass);
            }

            return $condition->isSatisfied();
        } catch (\Throwable $threw) {
            return $threw;
        }
    }

    /**
     * @param non-empty-string $conditionClass
     * @param list<scalar|null> $arguments
     */
    private function describeCondition(string $conditionClass, array $arguments): string
    {
        $separator = \strrpos($conditionClass, '\\');
        $shortName = $separator === false ? $conditionClass : \substr($conditionClass, $separator + 1);

        if ($arguments === []) {
            return $shortName;
        }

        // Replace invalid UTF-8 to keep a skipped result. Without replacement,
        // an encoder error changes the skip-reason conversion to a worker error.
        $rendered = \array_map(
            static fn(bool|float|int|string|null $argument): string => (string) \json_encode($argument, \JSON_INVALID_UTF8_SUBSTITUTE),
            $arguments,
        );

        return \sprintf('%s(%s)', $shortName, \implode(', ', $rendered));
    }

    private function skipped(PlanEntry $entry, string $reason): TestResult
    {
        return new TestResult($entry->id, Outcome::Skipped, 0.0, 0, skipReason: $reason);
    }
}
