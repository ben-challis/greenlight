<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Capture\OutputCapture;
use Greenlight\Core\Condition;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\UnresolvableService;

/**
 * Runs one plan entry: skip checks before construction, constructor
 * injection, before-hooks in declaration order, the test method, after-hooks
 * in reverse declaration order (always), per-test scope teardown, timeout
 * accounting, and the retry loop. Every reference to the test instance is
 * dropped when the attempt ends.
 *
 * @internal
 */
final readonly class TestExecutor
{
    public function __construct(
        private HarnessScopes $scopes,
        private ClassContext $context,
    ) {}

    public function execute(PlanEntry $entry): TestResult
    {
        $metadata = $entry->metadata;

        if ($metadata->skipReason !== null) {
            return $this->skipped($entry, $metadata->skipReason);
        }

        if ($metadata->skipUnlessCondition !== null) {
            $satisfied = $this->evaluateCondition($metadata->skipUnlessCondition);

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
                return $this->skipped($entry, \sprintf('Condition %s is not satisfied.', $metadata->skipUnlessCondition));
            }
        }

        $maxAttempts = 1 + ($metadata->retryTimes ?? 0);
        $attempt = 0;

        do {
            ++$attempt;
            [$result, $cause] = $this->attempt($entry, $attempt);

            if ($result->outcome->isSuccessful() || $attempt >= $maxAttempts) {
                return $result;
            }

            $retryOnlyOn = $metadata->retryOnlyOn;

            if ($retryOnlyOn !== null && !($cause instanceof $retryOnlyOn)) {
                return $result;
            }
        } while (true);
    }

    /**
     * @return array{TestResult, ?\Throwable} the result and the throwable that caused a non-pass, for retry matching
     */
    private function attempt(PlanEntry $entry, int $attempt): array
    {
        $metadata = $entry->metadata;
        $this->scopes->openTest();

        /** @var list<FailureDetail> $failures */
        $failures = [];
        $cause = null;
        $error = null;
        $captured = null;
        $capture = $metadata->capture ? new OutputCapture() : null;
        $memoryBefore = \memory_get_usage(true);
        $startedAt = \hrtime(true);
        $instance = null;
        $capture?->start();

        try {
            $instance = $this->instantiate($metadata->class);

            try {
                foreach ($this->context->beforeHooks as $hook) {
                    $hook->invoke($instance);
                }

                $arguments = [];

                if ($metadata->dataSetProvider !== null && $entry->id->dataSetKey !== null) {
                    $arguments = $this->context->argumentsFor(
                        $metadata->dataSetProvider,
                        $metadata->method,
                        $entry->id->dataSetKey,
                    );
                }

                $this->context->reflection->getMethod($metadata->method)->invokeArgs($instance, $arguments);
            } catch (ExpectationFailed $failed) {
                $failures = $failed->details;
                $cause = $failed;
            } catch (\Throwable $threw) {
                $cause = $threw;
                $error = ThrowableDetail::fromThrowable($threw);
            }

            foreach ($this->context->afterHooks as $hook) {
                try {
                    $hook->invoke($instance);
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
            $instance = null;
            $captured = $capture?->stop();
            $disposalFailures = $this->scopes->closeTest();

            if ($disposalFailures !== [] && !$cause instanceof \Throwable) {
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

        return [
            new TestResult(
                $entry->id,
                $outcome,
                \max(0.0, $durationSeconds),
                $memoryDelta,
                $attempt,
                $failures,
                $error,
                output: $captured,
            ),
            $cause,
        ];
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
            $arguments[] = $this->scopes->resolve($serviceType, $class);
        }

        return $this->context->reflection->newInstanceArgs($arguments);
    }

    /**
     * @param non-empty-string $conditionClass
     */
    private function evaluateCondition(string $conditionClass): bool|\Throwable
    {
        try {
            if (!\class_exists($conditionClass)) {
                return new \RuntimeException(\sprintf('Condition class %s does not exist.', $conditionClass));
            }

            $condition = new $conditionClass();

            if (!$condition instanceof Condition) {
                return new \RuntimeException(\sprintf(
                    'Condition class %s must implement %s.',
                    $conditionClass,
                    Condition::class,
                ));
            }

            return $condition->isSatisfied();
        } catch (\Throwable $threw) {
            return $threw;
        }
    }

    private function skipped(PlanEntry $entry, string $reason): TestResult
    {
        return new TestResult($entry->id, Outcome::Skipped, 0.0, 0, skipReason: $reason);
    }
}
