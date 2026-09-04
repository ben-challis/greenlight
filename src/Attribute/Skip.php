<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/** Skips a test method or all tests in a class with the specified reason. */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Skip
{
    /**
     * @var non-empty-string
     */
    public string $reason;

    /**
     * @param non-empty-string $reason
     *
     * @throws \InvalidArgumentException If $reason is empty.
     */
    public function __construct(string $reason)
    {
        if ($reason === '') {
            throw new \InvalidArgumentException('Skip reasons cannot be empty.');
        }

        $this->reason = $reason;
    }
}
