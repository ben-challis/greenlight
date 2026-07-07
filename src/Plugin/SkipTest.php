<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Control signal: throw from a test method, a before-hook, or a beforeTest
 * subscriber to report the test as skipped with the given reason.
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
