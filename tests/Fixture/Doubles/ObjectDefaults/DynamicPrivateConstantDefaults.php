<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

class DynamicPrivateConstantDefaults
{
    private const string SECRET = 'dynamic';

    public function run(Value $value = new Value('dynamic', [self::{'SECRET'}])): void {}
}
