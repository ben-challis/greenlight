<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Fails an otherwise passed attempt if its run time exceeds the specified
 * number of seconds. Greenlight checks elapsed time after per-test service
 * disposal. This check does not interrupt PHP code that is still running.
 * A class attribute applies the limit to each test in the class.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Timeout
{
    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public float $seconds,
    ) {
        if (!\is_finite($seconds) || $seconds <= 0.0) {
            throw new \InvalidArgumentException('Timeout seconds must be finite and greater than zero.');
        }
    }
}
