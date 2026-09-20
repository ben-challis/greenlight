<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

final class TrackedValue
{
    public static int $constructions = 0;

    public function __construct(public readonly string $label)
    {
        ++self::$constructions;
    }
}
