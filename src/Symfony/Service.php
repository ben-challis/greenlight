<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

/**
 * If the parameter type is not a unique container ID, use `#[Service]`. The
 * resolved service must have the declared type.
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
