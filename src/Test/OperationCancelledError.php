<?php

declare(strict_types=1);

namespace Greenlight\Test;

/**
 * Indicates that Greenlight stopped an operation at a deadline or lifecycle boundary.
 * Expectation matchers propagate this cancellation through exception wrappers.
 *
 * @internal
 */
abstract class OperationCancelledError extends \RuntimeException
{
    public static function find(\Throwable $failure): ?static
    {
        do {
            if ($failure instanceof static) {
                return $failure;
            }

            $failure = $failure->getPrevious();
        } while ($failure instanceof \Throwable);

        return null;
    }
}
