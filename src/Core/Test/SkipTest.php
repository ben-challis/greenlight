<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

/**
 * Reports a skipped test with the specified reason.
 *
 * Throw this signal from a test method, a before hook, or a `beforeTest()`
 * subscriber.
 */
final class SkipTest extends \Exception
{
    /**
     * @var non-empty-string
     */
    public readonly string $reason;

    /**
     * @throws \InvalidArgumentException If $reason is empty.
     */
    public function __construct(string $reason)
    {
        if ($reason === '') {
            throw new \InvalidArgumentException('Skip reasons cannot be empty.');
        }

        $this->reason = $reason;
        parent::__construct($reason);
    }
}
