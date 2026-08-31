<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Fails the test if its run time exceeds the specified number of seconds. A
 * class attribute applies the limit to each test in the class.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Timeout
{
    /**
     * @param non-negative-float $seconds
     *
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
