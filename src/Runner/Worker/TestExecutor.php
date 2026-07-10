<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Capture\OutputCapture;
use Greenlight\Core\Condition;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\ExpectationCounter;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\SkipTest;
use Greenlight\Plugin\TestContext;

/**
 * Runs one plan entry.
 *
 * execute() checks attribute skips before construction, then runs attempts
 * under the decider-driven retry loop.
 *
 * Each attempt covers constructor injection, beforeTest subscribers,
 * before-hooks in declaration order, the test method, after-hooks in reverse
 * declaration order, which always run, per-test scope teardown, and timeout
 * accounting. afterTest subscribers run with the provenance guard; see
 * applyAfterSubscribers() for the exact behaviour.
 *
 * Every reference to the test instance is dropped when the attempt ends.
 *
 * @internal
 */
final readonly class TestExecutor
{
    public function __construct(
        private HarnessScopes $scopes,
        private ClassContext $context,
        private PluginRegistry $plugins,
        private ?LeakDetector $leakDetector = null,
        private ?ResultPolicy $policy = null,
    ) {}

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

        do {
            ++$attempt;
            [$result, $cause] = $this->attempt($entry, $attempt);

            if ($result->outcome->isSuccessful()) {
                return $this->policy?->apply($result) ?? $result;
            }
            $retry = array_any($this->plugins->retryDeciders(), fn($decider) => $decider->shouldRetry($metadata, $result, $attempt, $cause));

            if (!$retry) {
                return $this->policy?->apply($result) ?? $result;
            }
        } while (true);
    }

    /**
     * @return array{TestResult, ?\Throwable} the result and the throwable that caused a non-pass, for retry matching
     */
    private function attempt(PlanEntry $entry, int $attempt): array
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
        $memoryBefore = \memory_get_usage(true);
        $startedAt = \hrtime(true);
        $capture?->start();

        try {
            $instance = $this->instantiate($metadata->class);
            $context = new TestContext($instance, $entry->id, $metadata, $this->scopes);
            $instance = null;

            foreach ($this->plugins->testSubscribers() as $subscriber) {
                try {
                    $subscriber->beforeTest($context);
                } catch (SkipTest $skip) {
                    $skipReason = $skip->reason;

                    break;
                } catch (\Throwable $threw) {
                    $cause = new \RuntimeException(\sprintf(
                        'Plugin "%s" failed in beforeTest: %s',
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

                // A disposal that throws expectation failures is a verification
                // step (auto-verified doubles); it fails the test with diffs
                // rather than erroring it.
                if ($cause instanceof ExpectationFailed) {
                    $failures = $cause->details;
                } else {
                    $error = ThrowableDetail::fromThrowable($cause);
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

        $budget = $metadata->timeoutSeconds;

        if ($budget !== null && $durationSeconds > $budget && $outcome === Outcome::Passed) {
            $outcome = Outcome::Failed;
            $failures = [new FailureDetail(\sprintf(
                'Timed out: budget %.3fs, took %.3fs.',
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

        // The counter includes double verification, which ran at scope close
        // above; a passed test with zero verified expectations is risky
        // unless it declared the intent with #[NoExpectations].
        if ($result->outcome === Outcome::Passed && !$metadata->noExpectations && $result->expectations === 0) {
            $result = $result->asRisky();
        }

        if ($context instanceof TestContext) {
            $result = $this->applyAfterSubscribers($context, $result);
            $this->leakDetector?->watch($entry->id, $context->instance);
        }

        return [$result, $cause];
    }

    /**
     * Runs afterTest subscribers with the provenance guard: an outcome change
     * that did not grow the transformation log is unattributable and errors
     * the test naming the plugin.
     *
     * A throwing subscriber errors a passing test naming the plugin. On a
     * test that already failed or errored, the original outcome and error are
     * kept so the plugin failure cannot mask them, and the plugin failure is
     * appended as a failure detail so it still surfaces in reports.
     */
    private function applyAfterSubscribers(TestContext $context, TestResult $result): TestResult
    {
        foreach ($this->plugins->testSubscribers() as $subscriber) {
            try {
                $replacement = $subscriber->afterTest($context, $result);
            } catch (\Throwable $threw) {
                if ($result->outcome->isSuccessful()) {
                    $result = new TestResult(
                        $result->id,
                        Outcome::Errored,
                        $result->durationSeconds,
                        $result->memoryDeltaBytes,
                        $result->attempts,
                        $result->failures,
                        ThrowableDetail::fromThrowable(new \RuntimeException(\sprintf(
                            'Plugin "%s" failed in afterTest: %s',
                            $subscriber::class,
                            $threw->getMessage(),
                        ), 0, $threw)),
                        $result->skipReason,
                        $result->transformations,
                        $result->output,
                        $result->risky,
                        $result->expectations,
                    );
                } else {
                    $result = new TestResult(
                        $result->id,
                        $result->outcome,
                        $result->durationSeconds,
                        $result->memoryDeltaBytes,
                        $result->attempts,
                        [...$result->failures, new FailureDetail(\sprintf(
                            'Plugin "%s" failed in afterTest: %s',
                            $subscriber::class,
                            $threw->getMessage(),
                        ))],
                        $result->error,
                        $result->skipReason,
                        $result->transformations,
                        $result->output,
                        $result->risky,
                        $result->expectations,
                    );
                }

                continue;
            }

            if ($replacement->outcome !== $result->outcome
                && \count($replacement->transformations) <= \count($result->transformations)
            ) {
                $result = new TestResult(
                    $result->id,
                    Outcome::Errored,
                    $result->durationSeconds,
                    $result->memoryDeltaBytes,
                    $result->attempts,
                    $result->failures,
                    ThrowableDetail::fromThrowable(new \RuntimeException(\sprintf(
                        'Plugin "%s" changed the outcome from %s to %s without withOutcome() provenance.',
                        $subscriber::class,
                        $result->outcome->value,
                        $replacement->outcome->value,
                    ))),
                    $result->skipReason,
                    $result->transformations,
                    $result->output,
                    $result->risky,
                    $result->expectations,
                );

                continue;
            }

            $result = $replacement;
        }

        return $result;
    }

    /**
     * @param non-empty-string $class
     */
    private function instantiate(string $class): object
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
                    'Condition class "%s" must implement %s.',
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

        // Substituting invalid UTF-8 keeps a skip a skip: a throwing encoder
        // would escalate the reason rendering into a worker error.
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
