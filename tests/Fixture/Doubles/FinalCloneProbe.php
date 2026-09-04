<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class FinalCloneProbe
{
    public static int $calls = 0;

    final public function __clone(): void
    {
        ++self::$calls;
    }
}
