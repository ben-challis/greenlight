<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

const SHARED_OBJECT = new Value('global');

interface ObjectConstantContract
{
    public const Value SHARED_VALUE = SHARED_OBJECT;
}
