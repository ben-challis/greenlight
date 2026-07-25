<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Worker-local temporal expectation context installed around each attempt.
 *
 * @internal
 */
final class ExpectationRuntime
{
    private static ?PollingClock $clock = null;

    private static ?float $deadline = null;

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
    }

    public static function leaveAttempt(): void
    {
        self::$deadline = null;
    }

    /**
     * Runs deterministic expectation tests against a supplied clock.
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
