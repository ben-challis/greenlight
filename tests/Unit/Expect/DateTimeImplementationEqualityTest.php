<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class DateTimeImplementationEqualityTest
{
    #[Test]
    public function mutableAndImmutableDateTimesCompareByInstant(): void
    {
        $mutable = new \DateTime('2026-07-28T12:00:00.123456+00:00');
        $immutable = new \DateTimeImmutable('2026-07-28T13:00:00.123456+01:00');

        Expect::that($mutable)
            ->because('date time equality MUST ignore implementation and time zone differences')
            ->toEqual($immutable);
    }
}
