<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface UnqualifiedConstantDefault
{
    public function largest(int $value = PHP_INT_MAX): int;

    public function smallest(int $value = PHP_INT_MIN): int;

    public function notANumber(float $value = NAN): float;

    public function infinite(float $value = INF): float;

    public function pi(float $value = M_PI): float;

    public function flags(int $value = JSON_THROW_ON_ERROR): int;

    public function lineEnd(string $value = PHP_EOL): string;
}
