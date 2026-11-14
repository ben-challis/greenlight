<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface ConstructorContract
{
    public function __construct(string $name, int $size = 1);

    public function name(): string;
}
