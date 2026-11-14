<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class DebugInfoStateService
{
    public function __construct(private readonly string $name) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name];
    }
}
