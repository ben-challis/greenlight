<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

/**
 * Use this attribute when a parameter type does not select one container ID.
 * The service must have the declared type.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Service
{
    /** @var non-empty-string */
    public string $id;

    /** @throws \InvalidArgumentException */
    public function __construct(string $id)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Service ID MUST NOT be empty.');
        }

        $this->id = $id;
    }
}
