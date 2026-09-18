<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class CanonicalPropertyKeyCollisionTest
{
    #[Test]
    public function canonicalEqualityDistinguishesPropertyNamesFromPropertyValues(): void
    {
        $first = (object) ['a' => null, 'b' => null];
        $second = (object) ['a=>null:NULL,b' => null];

        expect([$first, $second])->toEqualCanonicalizing([$second, $first]);
        expect([$first, $first])->not()->toEqualCanonicalizing([$first, $second]);
    }

    #[Test]
    public function canonicalEqualityPreservesQuotesAndBackslashesInPropertyNames(): void
    {
        $first = (object) ["a'" => null, 'b\\' => null];
        $second = (object) ["a'=>null:NULL,b\\" => null];

        expect([$first, $second])->toEqualCanonicalizing([$second, $first]);
    }
}
