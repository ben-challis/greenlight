<?php

declare(strict_types=1);

namespace Greenlight\Test;

/**
 * Indicates that a test or temporal expectation deadline stopped an asynchronous operation.
 *
 * @internal
 */
final class DeadlineExceededError extends OperationCancelledError
{
    private function __construct(public readonly bool $testDeadline)
    {
        parent::__construct($testDeadline
            ? 'The test time limit stopped an asynchronous operation.'
            : 'The temporal expectation time limit stopped an asynchronous operation.');
    }

    public static function forTest(): self
    {
        return new self(true);
    }

    public static function forTemporal(): self
    {
        return new self(false);
    }
}
