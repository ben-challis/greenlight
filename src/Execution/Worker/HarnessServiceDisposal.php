<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Expect\ExpectationFailed;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\DeadlineExceededError;

/**
 * Applies harness service disposal failures to their test or worker boundary.
 *
 * @internal
 */
final readonly class HarnessServiceDisposal
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param list<\Throwable> $failures
     */
    public static function applyToTest(TestResult $result, array $failures): TestResult
    {
        if ($failures === []) {
            return $result;
        }

        if (!$result->outcome->isSuccessful()) {
            return $result->withFailures([
                ...$result->failures,
                ...self::secondaryDetails($failures),
            ]);
        }

        $primary = \array_shift($failures);

        if (!$primary instanceof \Throwable) {
            throw new \LogicException('Harness service disposal failure list contains an invalid value.');
        }

        $deadline = DeadlineExceededError::find($primary);

        if ($deadline instanceof DeadlineExceededError) {
            return $result->failedByTeardown([
                new FailureDetail($deadline->getMessage()),
                ...self::secondaryDetails($failures),
            ]);
        }

        if ($primary instanceof ExpectationFailed) {
            return $result->failedByTeardown([
                ...$primary->details,
                ...self::secondaryDetails($failures),
            ]);
        }

        return $result->erroredByTeardown(
            ThrowableDetail::fromThrowable($primary),
            self::secondaryDetails($failures),
        );
    }

    /**
     * Runs one worker operation and closes its long-lived service scopes.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     *
     * @throws WorkerError
     */
    public static function runAndClose(HarnessScopes $scopes, \Closure $operation): mixed
    {
        try {
            $result = $operation();
        } catch (\Throwable $primary) {
            $failures = $scopes->closeWorker();

            if ($failures !== []) {
                throw WorkerError::afterHarnessServiceDisposal($primary, $failures);
            }

            throw $primary;
        }

        $failures = $scopes->closeWorker();

        if ($failures !== []) {
            throw WorkerError::harnessServiceDisposal($failures);
        }

        return $result;
    }

    /**
     * @param list<\Throwable> $failures
     *
     * @return list<FailureDetail>
     */
    private static function secondaryDetails(array $failures): array
    {
        $details = [];

        foreach ($failures as $failure) {
            $deadline = DeadlineExceededError::find($failure);

            if ($deadline instanceof DeadlineExceededError) {
                $details[] = new FailureDetail($deadline->getMessage());

                continue;
            }

            if ($failure instanceof ExpectationFailed) {
                $details = [...$details, ...$failure->details];

                continue;
            }

            $details[] = new FailureDetail(\sprintf(
                'Harness service disposal caused an error: %s',
                $failure->getMessage(),
            ));
        }

        return $details;
    }
}
