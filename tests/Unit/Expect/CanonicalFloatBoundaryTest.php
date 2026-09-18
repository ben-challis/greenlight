<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class CanonicalFloatBoundaryTest
{
    #[Test]
    #[DataSet('exactFloatIntegerBoundaries')]
    public function canonicalEqualityAlignsExactFloatIntegerBoundaries(int $integer, float $middle): void
    {
        expect([$integer, $middle])
            ->because('canonical equality MUST align an exactly representable boundary integer with its float')
            ->toEqualCanonicalizing([$middle, (float) $integer]);
    }

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function exactFloatIntegerBoundaries(): iterable
    {
        yield 'positive boundary' => [2 ** 53, 9.5];
        yield 'negative boundary' => [-(2 ** 53), -90.0];
    }
}
