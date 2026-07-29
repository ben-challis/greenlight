<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class ParentConstantDefault extends ParentConstantDefaultBase
{
    public const string MODE = 'child';

    public function mode(string $value = parent::MODE): string
    {
        return $value;
    }
}
