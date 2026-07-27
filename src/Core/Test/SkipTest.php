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
     * @param non-empty-string $reason
     */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
