<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Retries a failing test up to the given number of additional attempts,
 * optionally only when the failure was caused by the given throwable type.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Retry
{
    /**
     * @var positive-int
     */
    public int $times;

    /**
     * @param class-string<\Throwable>|null $onlyOn
     */
    public function __construct(
        int $times,
        public ?string $onlyOn = null,
    ) {
        if ($times < 1) {
            throw new \InvalidArgumentException('Retry times must be at least 1.');
        }

        $this->times = $times;
    }
}
