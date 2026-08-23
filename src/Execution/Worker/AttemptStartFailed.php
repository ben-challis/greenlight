<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

/**
 * The worker could not complete its attempt-start coordination callback.
 *
 * @internal
 */
final class AttemptStartFailed extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
