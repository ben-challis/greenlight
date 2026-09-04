<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class CanonicalDateTimeTest
{
    #[Test]
    public function dateListsCompareByInstantAcrossImplementations(): void
    {
        $first = new \DateTime('2026-01-01T00:00:00.123456+00:00');
        $second = new \DateTimeImmutable('2026-01-02T00:00:00.654321+00:00');

        Expect::that([$first, $second])->toEqualCanonicalizing([
            new \DateTime('2026-01-02T00:00:00.654321+00:00'),
            new \DateTimeImmutable('2026-01-01T00:00:00.123456+00:00'),
        ]);
    }

    #[Test]
    public function dateListsCompareByInstantAcrossTimeZones(): void
    {
        $first = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $second = new \DateTimeImmutable('2026-01-01T01:00:00+00:00');

        Expect::that([$first, $second])->toEqualCanonicalizing([
            new \DateTimeImmutable('2026-01-01T01:00:00+00:00'),
            new \DateTimeImmutable('2026-01-01T02:00:00+02:00'),
        ]);
    }

    #[Test]
    public function dateListsPreserveMicrosecondDifferences(): void
    {
        Expect::that([new \DateTime('2026-01-01T00:00:00.123456+00:00')])
            ->not()->toEqualCanonicalizing([
                new \DateTimeImmutable('2026-01-01T00:00:00.123457+00:00'),
            ]);
    }
}
