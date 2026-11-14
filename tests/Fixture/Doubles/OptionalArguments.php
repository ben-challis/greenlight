<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface OptionalArguments
{
    public function find(string $key, int $limit = 2, string $tag = 'none'): string;

    public function since(string $key, \DateTimeImmutable $at = new \DateTimeImmutable('2020-01-01 00:00:00 UTC')): string;
}
