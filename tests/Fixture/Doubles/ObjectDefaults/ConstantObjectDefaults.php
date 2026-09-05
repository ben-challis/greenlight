<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

interface ConstantObjectDefaults extends ObjectConstantContract
{
    /** @param list<Value> $values */
    public function nested(array $values = [SHARED_OBJECT], string $marker = ''): void;

    public function globalConstant(Value $value = SHARED_OBJECT, string $marker = ''): void;

    public function publicConstant(Value $value = self::SHARED_VALUE, string $marker = ''): void;
}
