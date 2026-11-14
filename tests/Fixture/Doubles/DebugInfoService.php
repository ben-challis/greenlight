<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class DebugInfoService
{
    public function name(): string
    {
        return 'actual';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['service' => 'debug'];
    }
}
