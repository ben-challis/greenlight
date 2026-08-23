<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Selects a container service ID that differs from the parameter type. The
 * resolved service must have the declared type.
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
            throw new \InvalidArgumentException('Service identifier must not be empty.');
        }

        $this->id = $id;
    }
}
