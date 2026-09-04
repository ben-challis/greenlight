<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class CanonicalSortCollisionTest
{
    #[Test]
    public function equalCyclicObjectsDoNotTriggerNativeRecursiveComparison(): void
    {
        $first = new \stdClass();
        $first->next = $first;
        $second = new \stdClass();
        $second->next = $second;

        Expect::that([$first, $second])->toEqualCanonicalizing([$second, $first]);
    }

    #[Test]
    public function nearbyFloatsKeepDistinctSortPositions(): void
    {
        $first = 1.000000000000001;
        $second = 1.000000000000002;

        Expect::that([$first, $second])->toEqualCanonicalizing([$second, $first]);
        Expect::that([$first, $first])->not()->toEqualCanonicalizing([$first, $second]);
    }

    #[Test]
    public function exactLargeIntegerAndFloatValuesShareSortPositions(): void
    {
        $integer = 2 ** 54;

        Expect::that([$integer, 17.0])->toEqualCanonicalizing([17.0, (float) $integer]);
    }

    #[Test]
    public function exactLargeNegativeIntegerAndFloatValuesShareSortPositions(): void
    {
        $integer = -(2 ** 54);

        Expect::that([$integer, -17.0])->toEqualCanonicalizing([-17.0, (float) $integer]);
    }

    #[Test]
    public function signedZeroValuesShareSortPositions(): void
    {
        Expect::that([-0.0, -1.0])->toEqualCanonicalizing([-1.0, 0.0]);
    }
}
