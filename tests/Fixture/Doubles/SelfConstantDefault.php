<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface SelfConstantDefault
{
    public const string MODE = 'fast';

    public function mode(string $value = self::MODE): string;
}
