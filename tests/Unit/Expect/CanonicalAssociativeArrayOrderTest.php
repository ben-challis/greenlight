<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class CanonicalAssociativeArrayOrderTest
{
    #[Test]
    public function canonicalEqualityIgnoresAssociativeKeyInsertionOrder(): void
    {
        $first = ['a' => 1, 'b' => 2];
        $second = ['a' => 2, 'b' => 1];
        $equivalentFirst = ['b' => 2, 'a' => 1];
        $equivalentSecond = ['b' => 1, 'a' => 2];

        Expect::that([$first, $second])
            ->because('canonical equality MUST ignore associative key insertion order')
            ->toEqualCanonicalizing([$equivalentFirst, $equivalentSecond]);
    }
}
