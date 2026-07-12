<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class Clock
{
    public function __construct()
    {
        throw new \RuntimeException('The Clock constructor must never run for a double.');
    }

    public function now(): string
    {
        return \date('c');
    }
}
