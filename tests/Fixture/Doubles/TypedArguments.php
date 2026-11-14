<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface TypedArguments
{
    public function scale(float $factor): float;

    public function accept(mixed $value): void;
}
