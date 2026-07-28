<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Skip
{
    /**
     * @var non-empty-string
     */
    public string $reason;

    /**
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
