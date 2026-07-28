<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface GlobalConstantDefault
{
    public function limit(int $value = \PHP_INT_MAX): int;
}
