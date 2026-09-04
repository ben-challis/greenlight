<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Expect\Node;

final readonly class CanonicalSortCollisionTest
{
    #[Test]
    public function canonicalEqualityAcceptsEqualCyclicListElements(): void
    {
        $first = new Node();
        $first->next = $first;
        $second = new Node();
        $second->next = $second;

        Expect::that([$first, $second])->toEqualCanonicalizing([$second, $first]);
    }

    #[Test]
    #[DataSet('exactNumbers')]
    public function canonicalEqualityAlignsExactIntegersAndFloats(int $integer, float $float, int $other): void
    {
        Expect::that([$integer, $other])->toEqualCanonicalizing([(float) $other, $float]);
    }

    /** @return iterable<string, array{int, float, int}> */
    public static function exactNumbers(): iterable
    {
        yield 'large positive integer' => [2 ** 54, (float) (2 ** 54), 15];
        yield 'large negative integer' => [-(2 ** 54), (float) -(2 ** 54), -15];
        yield 'negative zero' => [0, -0.0, -1];
    }

    #[Test]
    public function canonicalEqualityDistinguishesFloatsWithTheSameShortRepresentation(): void
    {
        $first = 1.000000000000001;
        $second = 1.000000000000002;

        Expect::that([$second, $first])->toEqualCanonicalizing([$first, $second]);
        Expect::that([$first, $first])->not()->toEqualCanonicalizing([$first, $second]);
    }
}
