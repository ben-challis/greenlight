<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class CanonicalPropertyKeyCollisionTest
{
    #[Test]
    public function canonicalEqualityDistinguishesPropertyNamesFromPropertyValues(): void
    {
        $first = (object) ['a' => null, 'b' => null];
        $second = (object) ['a=>null:NULL,b' => null];

        Expect::that([$first, $second])->toEqualCanonicalizing([$second, $first]);
        Expect::that([$first, $first])->not()->toEqualCanonicalizing([$first, $second]);
    }

    #[Test]
    public function canonicalEqualityPreservesQuotesAndBackslashesInPropertyNames(): void
    {
        $first = (object) ["a'" => null, 'b\\' => null];
        $second = (object) ["a'=>null:NULL,b\\" => null];

        Expect::that([$first, $second])->toEqualCanonicalizing([$second, $first]);
    }
}
