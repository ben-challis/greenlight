<?php

declare(strict_types=1);

namespace Greenlight\Laravel;

/**
 * Use when the parameter type is not a unique container binding. The resolved
 * service must still satisfy the declared type.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Service
{
    /**
     * @var non-empty-string
     */
    public string $id;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Service identifier must not be empty.');
        }

        $this->id = $id;
    }
}
