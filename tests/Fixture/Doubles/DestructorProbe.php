<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class DestructorProbe
{
    public static int $calls = 0;

    public function __destruct()
    {
        ++self::$calls;
    }
}
