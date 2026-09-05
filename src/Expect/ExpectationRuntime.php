<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Stores the poll clock, test deadline, and active temporal scopes.
 * Each Fiber has a separate temporal scope. The test deadline applies to all Fibers.
 *
 * @internal
 */
final class ExpectationRuntime
{
    private static ?PollingClock $clock = null;

    private static ?float $deadline = null;

    private static ?float $mainDeadline = null;

    /** @var \WeakMap<object, float>|null */
    private static ?\WeakMap $fiberDeadlines = null;

    private static int $generation = 0;

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function clock(): PollingClock
    {
        return self::$clock ??= new SystemPollingClock();
    }

    public static function deadline(): ?float
    {
        return self::$deadline;
    }

    public static function enterAttempt(?float $deadline): void
    {
        self::$deadline = $deadline;
        self::$mainDeadline = null;
        self::$fiberDeadlines = null;
        ++self::$generation;
    }

    public static function leaveAttempt(): void
    {
        self::enterAttempt(null);
    }

    public static function enclosingDeadline(): ?float
    {
        $fiber = \Fiber::getCurrent();

        return $fiber instanceof \Fiber ? (self::$fiberDeadlines[$fiber] ?? null) : self::$mainDeadline;
    }

    /**
     * Runs an observation within the current Fiber's temporal time limit.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public static function withDeadline(float $deadline, \Closure $operation): mixed
    {
        $fiber = \Fiber::getCurrent();
        $previous = self::enclosingDeadline();
        $generation = self::$generation;
        $deadline = $previous === null ? $deadline : \min($previous, $deadline);
        $fiberDeadlines = self::$fiberDeadlines ??= new \WeakMap();

        if (!$fiber instanceof \Fiber) {
            self::$mainDeadline = $deadline;
        } else {
            $fiberDeadlines[$fiber] = $deadline;
        }

        try {
            return $operation();
        } finally {
            if (self::$generation === $generation) {
                if (!$fiber instanceof \Fiber) {
                    self::$mainDeadline = $previous;
                } elseif ($previous === null) {
                    unset($fiberDeadlines[$fiber]);
                } else {
                    $fiberDeadlines[$fiber] = $previous;
                }
            }
        }
    }

    /**
     * Runs an operation with a temporary poll clock.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public static function withClock(PollingClock $clock, \Closure $operation): mixed
    {
        $previous = self::$clock;
        self::$clock = $clock;

        try {
            return $operation();
        } finally {
            self::$clock = $previous;
        }
    }
}
