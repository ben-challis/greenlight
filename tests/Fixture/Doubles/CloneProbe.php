<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class CloneProbe
{
    public static int $calls = 0;

    public function __clone(): void
    {
        ++self::$calls;
    }
}
