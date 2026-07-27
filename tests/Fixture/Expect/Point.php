<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

final class Point
{
    public function __construct(
        public int $x,
        private readonly int $y,
    ) {}

    public function y(): int
    {
        return $this->y;
    }
}
