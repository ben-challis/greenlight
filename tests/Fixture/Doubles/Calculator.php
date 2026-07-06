<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface Calculator
{
    public function add(int $a, int $b): int;

    public function describe(string $label): string;
}
