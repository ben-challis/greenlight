<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Starts up to `$times` additional attempts after an unsuccessful test attempt.
 * If `$onlyOn` specifies a throwable type, Greenlight starts another attempt
 * only when the cause has that type.
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
