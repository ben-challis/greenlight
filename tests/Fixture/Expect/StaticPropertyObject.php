<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

use Greenlight\Doubles\Fake;

final class StaticPropertyObject implements Fake
{
    public static int $shared = 1;

    public int $local = 2;
}
