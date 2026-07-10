<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class CanonicalizingTest
{
    #[Test]
    public function toEqualCanonicalizingIgnoresListOrder(): void
    {
        Expect::that([3, 1, 2])->toEqualCanonicalizing([1, 2, 3]);
        Expect::that(['b', 'a'])->toEqualCanonicalizing(['a', 'b']);
    }

    #[Test]
    public function toEqualCanonicalizingIgnoresNestedListOrder(): void
    {
        Expect::that([
            'a' => [2, 1],
            'b' => [[4, 3], [2, 1]],
        ])->toEqualCanonicalizing([
            'b' => [[1, 2], [3, 4]],
            'a' => [1, 2],
        ]);
    }

    #[Test]
    public function toEqualCanonicalizingReordersIntsBeyondFloatPrecision(): void
    {
        $a = 9_007_199_254_740_993;
        $b = 9_007_199_254_740_992;

        Expect::that([$a, $b])->toEqualCanonicalizing([$b, $a]);
        Expect::that([$a, $a])->not()->toEqualCanonicalizing([$b, $a]);
    }

    #[Test]
    public function toEqualCanonicalizingKeepsAssociativeKeys(): void
    {
        Expect::that(['x' => 1, 'y' => 2])->toEqualCanonicalizing(['y' => 2, 'x' => 1]);
        Expect::that(['x' => 1])->not()->toEqualCanonicalizing(['y' => 1]);
    }

    #[Test]
    public function toEqualCanonicalizingDelegatesToDeepEquality(): void
    {
        Expect::that(1)->toEqualCanonicalizing(1.0);
        Expect::that([1, 'a'])->toEqualCanonicalizing(['a', 1.0]);
    }

    #[Test]
    public function toEqualCanonicalizingFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([1, 2])->toEqualCanonicalizing([1, 2, 3]),
        );

        Expect::that($detail->message)->toBe('Expected [1, 2] to equal (canonicalizing) [1, 2, 3].');
        Expect::that($detail->expected)->toBe('[1, 2, 3]');
    }

    #[Test]
    public function notToEqualCanonicalizing(): void
    {
        Expect::that([1, 2])->not()->toEqualCanonicalizing([1, 2, 3]);
    }
}
