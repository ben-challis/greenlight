<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface OptionalArguments
{
    public function find(string $key, int $limit = 2, string $tag = 'none'): string;

    /**
     * @param list<float> $samples
     */
    public function measure(string $key, float $value = \NAN, array $samples = [\NAN]): string;

    public function since(string $key, \DateTimeImmutable $at = new \DateTimeImmutable('2020-01-01 00:00:00 UTC')): string;
}
