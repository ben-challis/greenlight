<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Condition\Condition;

/**
 * A worker cannot complete an assigned test or plugin lifecycle operation.
 *
 * @internal
 */
final class WorkerError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /** @param non-empty-string $class */
    public static function classUnavailable(string $class): self
    {
        return new self(\sprintf(
            'This process cannot load test class "%s" from the execution plan.',
            $class,
        ));
    }

    /**
     * @param class-string $class
     * @param non-empty-string $method
     */
    public static function dataSetMissing(string $key, string $class, string $method): self
    {
        return new self(\sprintf(
            'The execution plan contains data set "%s" for "%s::%s()", but its data provider no longer returns it. '
            . 'Run discovery again.',
            $key,
            $class,
            $method,
        ));
    }

    /**
     * @param class-string $class
     * @param non-empty-string $method
     */
    public static function invalidDataSet(string $key, string $class, string $method, mixed $actual): self
    {
        return new self(\sprintf(
            'Data set "%s" of "%s::%s()" requires an argument array. Actual type: %s.',
            $key,
            $class,
            $method,
            \get_debug_type($actual),
        ));
    }

    /** @param non-empty-string $class */
    public static function conditionClassMissing(string $class): self
    {
        return new self(\sprintf('Condition class "%s" does not exist.', $class));
    }

    /** @param class-string $class */
    public static function invalidConditionClass(string $class): self
    {
        return new self(\sprintf(
            'Condition class "%s" does not implement %s.',
            $class,
            Condition::class,
        ));
    }

    public static function crashedDuringTest(string $workerId, string $reason, string $diagnostics): self
    {
        $message = \sprintf('Worker "%s" crashed during this test: %s.', $workerId, $reason);

        if ($diagnostics !== '') {
            $message .= "\nWorker output:\n" . $diagnostics;
        }

        return new self($message);
    }

    /**
     * @param non-empty-list<\Throwable> $failures
     */
    public static function harnessServiceDisposal(array $failures): self
    {
        return new self(self::appendHarnessServiceDisposalFailures(
            'Worker harness service disposal failed.',
            $failures,
        ), previous: $failures[0]);
    }

    /**
     * @param non-empty-list<\Throwable> $failures
     */
    public static function afterHarnessServiceDisposal(\Throwable $primary, array $failures): self
    {
        return new self(self::appendHarnessServiceDisposalFailures(
            \sprintf('The worker failed with %s: %s', $primary::class, self::sentence($primary->getMessage())),
            $failures,
        ), previous: $primary);
    }

    /**
     * @param non-empty-list<\Throwable> $failures
     */
    private static function appendHarnessServiceDisposalFailures(string $message, array $failures): string
    {
        foreach ($failures as $failure) {
            $message .= \sprintf(
                "\nAdditionally, harness service disposal failed with %s: %s",
                $failure::class,
                self::sentence($failure->getMessage()),
            );
        }

        return $message;
    }

    private static function sentence(string $message): string
    {
        $message = \rtrim($message);

        if ($message === '') {
            return 'No message was provided.';
        }

        return \preg_match('/[.!?]\z/', $message) === 1 ? $message : $message . '.';
    }
}
