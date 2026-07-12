<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageLib;

final class Math
{
    public function add(int $a, int $b): int
    {
        $sum = $a + $b;

        return $sum;
    }

    public function neverCalled(int $a): int
    {
        $doubled = $a * 2;

        return $doubled;
    }
}
