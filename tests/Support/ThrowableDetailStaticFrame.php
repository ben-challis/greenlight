<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Doubles\Fake;

final class ThrowableDetailStaticFrame implements Fake
{
    public static function fail(mixed $value): never
    {
        throw new \RuntimeException('internal frame');
    }
}
