<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

class PrivateObjectConstantDefaults implements ObjectConstantContract
{
    private const Value OBJECT = SHARED_OBJECT;

    public function run(Value $value = self::OBJECT): void {}
}
