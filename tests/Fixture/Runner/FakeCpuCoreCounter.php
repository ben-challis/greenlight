<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner;

use Greenlight\Doubles\Fake;

final class FakeCpuCoreCounter implements Fake
{
    public static bool $notFound = false;

    public static int $calls = 0;

    public function getCount(): int
    {
        ++self::$calls;

        if (self::$notFound) {
            throw new FakeNumberOfCpuCoreNotFound();
        }

        return 7;
    }
}
