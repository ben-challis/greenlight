<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface DebugInfoContract
{
    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array;

    public function name(): string;
}
