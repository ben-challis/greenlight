<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Runs an unsuccessful test attempt again for the specified number of
 * additional attempts. The optional throwable type limits the unsuccessful
 * attempts that start another attempt.
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
     *
     * @throws \InvalidArgumentException
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
