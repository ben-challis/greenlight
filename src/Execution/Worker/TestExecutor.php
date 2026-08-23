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
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultPolicy;
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
 * - Constructor injection
 * - `beforeTest()` subscribers
 * - `Before` hooks
 * - The test method
 * - `After` hooks
 * - Deferred cleanup callbacks
 * - Test-scope teardown
 * - Timeout enforcement
 * - `afterTest()` subscribers
 *
 * `Before` hooks use declaration order. `After` hooks use reverse declaration
 * order.
 *
 * An `After` hook runs if constructor injection created a test instance. It
 * runs even when a previous test-body operation did not complete.
 * `applyAfterSubscribers()` preserves the test identity. It validates each
 * outcome change against the transformation log. Greenlight removes each
 * reference to the test instance when the attempt ends.
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
        private ?ResultPolicy $policy = null,
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
                [$result, $cause, $attachments] = $this->runTestAttempt(
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

            if ($result->outcome->isSuccessful()) {
                $result = $this->policy?->apply($result) ?? $result;
                $sealed = $attachments?->seal() ?? [];

                return $result->withAttachments([...$retainedAttachments, ...$sealed]);
            }

            try {
                $retry = $this->plugins->shouldRetry($definition->retry, $result, $attempt, $cause);
            } catch (\Throwable $threw) {
                $result = $result->erroredBy(ThrowableDetail::fromThrowable($threw));
                $sealed = $attachments?->seal() ?? [];

                return $result->withAttachments([...$retainedAttachments, ...$sealed]);
            }

            $sealed = $attachments?->seal() ?? [];

            if (!$retry) {
                $result = $this->policy?->apply($result) ?? $result;

                return $result->withAttachments([...$retainedAttachments, ...$sealed]);
            }

            $retainedAttachments = [...$retainedAttachments, ...$sealed];
        } while (true);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $attempt
     *
     * @return T
     */
    private function runTestAttempt(\Closure $attempt): mixed
    {
        return $this->plugins->runTestAttempt($attempt);
    }

    /**
     * @return array{TestResult, ?\Throwable, ?StagedAttachments}
     * @throws CaptureError
     * @throws AttachmentError
     */
    private function attempt(PlanEntry $entry, int $attempt, TestArtifactBudget $artifactBudget): array
    {
        $definition = $entry->definition;
        $execution = $definition->execution;
        ExpectationCounter::reset();
        $this->scopes->openTest();

        /** @var list<FailureDetail> $failures */
        $failures = [];
        $cause = null;
        $error = null;
        $skipReason = null;
        $captured = null;
        $context = null;
        $capture = $execution->capture ? new OutputCapture() : null;
        $stagedAttachments = $this->artifactStore?->forAttempt($entry->id, $attempt, $artifactBudget);
        $attachments = $stagedAttachments ?? new UnavailableAttachments();
        $cleanup = new Cleanup();
        $disposalFailures = [];
        $memoryBefore = \memory_get_usage(true);
        $startedAt = \hrtime(true);
        $capture?->start();
        ExpectationRuntime::enterAttempt(
            $execution->timeoutSeconds === null
                ? null
                : $startedAt / 1_000_000_000 + $execution->timeoutSeconds,
        );

        try {
            $instance = $this->instantiate($definition->class, $attachments, $cleanup);
            $context = new TestContext($instance, $entry->id, $definition, $this->scopes, $attachments);
            $instance = null;

            try {
                $this->plugins->beforeTest($context);
            } catch (SkipTest $skip) {
                $skipReason = $skip->reason;
            } catch (PluginRuntimeError $failure) {
                $cause = $failure;
                $error = ThrowableDetail::fromThrowable($failure);
            }

            if ($skipReason === null && !$cause instanceof PluginRuntimeError) {
                try {
                    foreach ($this->context->beforeHooks as $hook) {
                        $hook->invoke($context->instance);
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

                    $this->context->reflection->getMethod($definition->method)->invokeArgs($context->instance, $arguments);
                } catch (SkipTest $skip) {
                    $skipReason = $skip->reason;
                } catch (ExpectationFailed $failed) {
                    $failures = $failed->details;
                    $cause = $failed;
                } catch (\Throwable $threw) {
                    $cause = $threw;
                    $error = ThrowableDetail::fromThrowable($threw);
                }
            }

            foreach ($this->context->afterHooks as $hook) {
                try {
                    $hook->invoke($context->instance);
                } catch (\Throwable $threw) {
                    if (!$cause instanceof \Throwable) {
                        $cause = $threw;
                        $error = ThrowableDetail::fromThrowable($threw);
                    }
                }
            }
        } catch (\Throwable $threw) {
            $cause = $threw;
            $error = ThrowableDetail::fromThrowable($threw);
        } finally {
            try {
                $captured = $capture?->stop();
            } finally {
                try {
                    try {
                        $cleanup->close();
                    } catch (CleanupFailed $cleanupFailed) {
                        foreach ($cleanupFailed->failures as $cleanupFailure) {
                            if (!$cause instanceof \Throwable) {
                                $cause = $cleanupFailure;
                                $skipReason = null;

                                if ($cleanupFailure instanceof ExpectationFailed) {
                                    $failures = $cleanupFailure->details;
                                } else {
                                    $error = ThrowableDetail::fromThrowable($cleanupFailure);
                                }

                                continue;
                            }

                            if ($cleanupFailure instanceof ExpectationFailed) {
                                $failures = [...$failures, ...$cleanupFailure->details];

                                continue;
                            }

                            $failures[] = new FailureDetail(\sprintf(
                                'Cleanup callback caused an error: %s',
                                $cleanupFailure->getMessage(),
                            ));
                        }
                    }
                } finally {
                    try {
                        $disposalFailures = $this->scopes->closeTest();
                    } finally {
                        ExpectationRuntime::leaveAttempt();
                    }
                }
            }
        }

        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $memoryDelta = \memory_get_usage(true) - $memoryBefore;

        $outcome = match (true) {
            $error instanceof ThrowableDetail => Outcome::Errored,
            $failures !== [] => Outcome::Failed,
            $skipReason !== null => Outcome::Skipped,
            default => Outcome::Passed,
        };

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
            $entry->id,
            $outcome,
            \max(0.0, $durationSeconds),
            $memoryDelta,
            $attempt,
            $failures,
            $error,
            $skipReason,
            output: $captured,
            expectations: ExpectationCounter::count(),
        );

        $result = HarnessServiceDisposal::applyToTest($result, $disposalFailures);

        if (!$cause instanceof \Throwable && $disposalFailures !== []) {
            $cause = $disposalFailures[0];
        }

        // The counter includes double verification from scope close. A passed
        // test with no verified expectations is a risky test unless it has
        // #[NoExpectations].
        if ($result->outcome === Outcome::Passed && !$execution->noExpectations && $result->expectations === 0) {
            $result = $result->asRisky();
        }

        if ($context instanceof TestContext) {
            $result = $this->plugins->afterTest($context, $result);
            $this->leakDetector?->watch($entry->id, $context->instance);
        }

        return [$result, $cause, $stagedAttachments];
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

            if ($serviceType === Attachments::class) {
                $arguments[] = $attachments;

                continue;
            }

            if ($serviceType === Cleanup::class) {
                $arguments[] = $cleanup;

                continue;
            }

            $attributes = \array_map(
                static fn(\ReflectionAttribute $attribute): object => $attribute->newInstance(),
                $parameter->getAttributes(),
            );
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
