<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Capture\CaptureError;
use Greenlight\Capture\OutputCapture;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\Attachments;
use Greenlight\Core\Artifact\UnavailableAttachments;
use Greenlight\Core\Condition;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\ExpectationCounter;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Core\Test\TestId;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\ServiceResolutionError;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\TestContext;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\StagedAttachments;
use Greenlight\Runner\Artifact\TestArtifactBudget;

/**
 * Each test attempt can have these operations, in this order:
 *
 * - Constructor injection
 * - `beforeTest()` subscribers
 * - `Before` hooks
 * - The test method
 * - `After` hooks
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
        private PluginRegistry $plugins,
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
        $metadata = $entry->metadata;

        if ($metadata->skipReason !== null) {
            return $this->skipped($entry, $metadata->skipReason);
        }

        if ($metadata->skipUnlessCondition !== null) {
            $satisfied = $this->evaluateCondition($metadata->skipUnlessCondition, $metadata->skipUnlessArguments);

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
                    'Condition %s is not satisfied.',
                    $this->describeCondition($metadata->skipUnlessCondition, $metadata->skipUnlessArguments),
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

            [$result, $cause, $attachments] = $this->attempt($entry, $attempt, $artifactBudget);

            if ($attachments instanceof StagedAttachments) {
                $result = $result->withAttachments($attachments->collected());
            }

            if ($result->outcome->isSuccessful()) {
                $result = $this->policy?->apply($result) ?? $result;
                $sealed = $attachments?->seal() ?? [];

                return $result->withAttachments([...$retainedAttachments, ...$sealed]);
            }

            try {
                $retry = \array_any(
                    $this->plugins->retryDeciders(),
                    fn($decider) => $decider->shouldRetry($metadata, $result, $attempt, $cause),
                );
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
     * @return array{TestResult, ?\Throwable, ?StagedAttachments}
     * @throws CaptureError
     * @throws AttachmentError
     */
    private function attempt(PlanEntry $entry, int $attempt, TestArtifactBudget $artifactBudget): array
    {
        $metadata = $entry->metadata;
        ExpectationCounter::reset();
        $this->scopes->openTest();

        /** @var list<FailureDetail> $failures */
        $failures = [];
        $cause = null;
        $error = null;
        $skipReason = null;
        $captured = null;
        $context = null;
        $capture = $metadata->capture ? new OutputCapture() : null;
        $stagedAttachments = $this->artifactStore?->forAttempt($entry->id, $attempt, $artifactBudget);
        $attachments = $stagedAttachments ?? new UnavailableAttachments();
        $memoryBefore = \memory_get_usage(true);
        $startedAt = \hrtime(true);
        $capture?->start();
        ExpectationRuntime::enterAttempt(
            $metadata->timeoutSeconds === null
                ? null
                : $startedAt / 1_000_000_000 + $metadata->timeoutSeconds,
        );

        try {
            $instance = $this->instantiate($metadata->class, $attachments);
            $context = new TestContext($instance, $entry->id, $metadata, $this->scopes, $attachments);
            $instance = null;

            foreach ($this->plugins->testSubscribers() as $subscriber) {
                try {
                    $subscriber->beforeTest($context);
                } catch (SkipTest $skip) {
                    $skipReason = $skip->reason;

                    break;
                } catch (\Throwable $threw) {
                    $cause = new \RuntimeException(\sprintf(
                        'Plugin "%s" caused an error during beforeTest(): %s',
                        $subscriber::class,
                        $threw->getMessage(),
                    ), 0, $threw);
                    $error = ThrowableDetail::fromThrowable($cause);

                    break;
                }
            }

            if ($skipReason === null && !$cause instanceof \RuntimeException) {
                try {
                    foreach ($this->context->beforeHooks as $hook) {
                        $hook->invoke($context->instance);
                    }

                    $arguments = [];

                    if ($entry->id->dataSetKey !== null) {
                        $arguments = $this->context->argumentsFor(
                            $metadata->dataSetProvider,
                            $metadata->dataSetProviderClass,
                            $metadata->method,
                            $entry->id->dataSetKey,
                        );
                    }

                    $this->context->reflection->getMethod($metadata->method)->invokeArgs($context->instance, $arguments);
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
            $captured = $capture?->stop();
            $disposalFailures = $this->scopes->closeTest();

            if ($disposalFailures !== [] && !$cause instanceof \Throwable && $skipReason === null) {
                $cause = $disposalFailures[0];

                // An ExpectationFailed from disposal is a verification step.
                // Automatic double verification uses this path. It fails the
                // test with differences instead of an error.
                if ($cause instanceof ExpectationFailed) {
                    $failures = $cause->details;
                } else {
                    $error = ThrowableDetail::fromThrowable($cause);
                }
            }

            ExpectationRuntime::leaveAttempt();
        }

        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $memoryDelta = \memory_get_usage(true) - $memoryBefore;

        $outcome = match (true) {
            $error instanceof ThrowableDetail => Outcome::Errored,
            $failures !== [] => Outcome::Failed,
            $skipReason !== null => Outcome::Skipped,
            default => Outcome::Passed,
        };

        $budget = $metadata->timeoutSeconds;

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

        // The counter includes double verification from scope close. A passed
        // test with no verified expectations is a risky test unless it has
        // #[NoExpectations].
        if ($result->outcome === Outcome::Passed && !$metadata->noExpectations && $result->expectations === 0) {
            $result = $result->asRisky();
        }

        if ($context instanceof TestContext) {
            $result = $this->applyAfterSubscribers($context, $result);
            $this->leakDetector?->watch($entry->id, $context->instance);
        }

        return [$result, $cause, $stagedAttachments];
    }

    /**
     * Runs afterTest() subscribers. It preserves the test identity and validates
     * each outcome change against the transformation log.
     *
     * A change without a new transformation-log entry has no source. This
     * condition causes a test error that identifies the plugin.
     *
     * A throwable from a subscriber causes an error in a passed test and
     * identifies the plugin. For a failed or errored test, Greenlight keeps
     * the original outcome and error. It adds the plugin failure as a failure
     * detail. Thus, reports show the plugin failure without loss of the
     * original error.
     */
    private function applyAfterSubscribers(TestContext $context, TestResult $result): TestResult
    {
        foreach ($this->plugins->testSubscribers() as $subscriber) {
            try {
                $replacement = $subscriber->afterTest($context, $result);
            } catch (\Throwable $threw) {
                if ($result->outcome->isSuccessful()) {
                    $result = $result->erroredBy(
                        ThrowableDetail::fromThrowable(new \RuntimeException(\sprintf(
                            'Plugin "%s" caused an error during afterTest(): %s',
                            $subscriber::class,
                            $threw->getMessage(),
                        ), 0, $threw)),
                    );
                } else {
                    $result = $result->withFailures([
                        ...$result->failures,
                        new FailureDetail(\sprintf(
                            'Plugin "%s" caused an error during afterTest(): %s',
                            $subscriber::class,
                            $threw->getMessage(),
                        )),
                    ]);
                }

                continue;
            }

            if (!$replacement->id->equals($result->id)) {
                $result = $result->erroredBy(
                    ThrowableDetail::fromThrowable(new \RuntimeException(\sprintf(
                        'Plugin "%s" changed the test identity during afterTest() from "%s" to "%s".',
                        $subscriber::class,
                        $result->id,
                        $replacement->id,
                    ))),
                );

                continue;
            }

            if ($replacement->outcome !== $result->outcome
                && \count($replacement->transformations) <= \count($result->transformations)
            ) {
                $result = $result->erroredBy(
                    ThrowableDetail::fromThrowable(new \RuntimeException(\sprintf(
                        'Plugin "%s" changed the outcome from %s to %s without a new transformation-log entry from withOutcome().',
                        $subscriber::class,
                        $result->outcome->value,
                        $replacement->outcome->value,
                    ))),
                );

                continue;
            }

            $result = $replacement;
        }

        return $result;
    }

    /**
     * @param non-empty-string $class
     * @throws UnresolvableService
     * @throws ServiceResolutionError
     */
    private function instantiate(string $class, Attachments $attachments): object
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
                return new \RuntimeException(\sprintf('Condition class "%s" does not exist.', $conditionClass));
            }

            $condition = new $conditionClass(...$arguments);

            if (!$condition instanceof Condition) {
                return new \RuntimeException(\sprintf(
                    'Condition class "%s" does not implement %s.',
                    $conditionClass,
                    Condition::class,
                ));
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
