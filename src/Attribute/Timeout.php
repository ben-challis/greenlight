<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Fails the test method, or every test in the class, when it runs longer
 * than the given number of seconds.
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
        if ($seconds <= 0.0) {
            throw new \InvalidArgumentException('Timeout seconds must be greater than zero.');
        }
    }
}
