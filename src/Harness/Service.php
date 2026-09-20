<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Selects a service ID or a named source for a constructor parameter. Each
 * bridge translates the ID into its container lookup, or uses the default
 * lookup if the ID is absent. The service must have the declared type.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Service
{
    /** @var non-empty-string|null */
    public ?string $id;

    /** @var non-empty-string|null */
    public ?string $source;

    /** @throws \InvalidArgumentException */
    public function __construct(?string $id = null, ?string $source = null)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Service identifier must not be empty.');
        }

        if ($source === '') {
            throw new \InvalidArgumentException('Service source must not be empty.');
        }

        $this->id = $id;
        $this->source = $source;
    }
}
