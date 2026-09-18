<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Expect\CanonicalNode;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class CanonicalizingTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function toEqualCanonicalizingIgnoresListOrder(): void
    {
        expect([3, 1, 2])->because('toEqualCanonicalizing() ignores list order')->toEqualCanonicalizing([1, 2, 3]);
        expect(['b', 'a'])->because('toEqualCanonicalizing() ignores list order')->toEqualCanonicalizing(['a', 'b']);
    }

    #[Test]
    public function toEqualCanonicalizingIgnoresNestedListOrder(): void
    {
        expect([
            'a' => [2, 1],
            'b' => [[4, 3], [2, 1]],
        ])->because('toEqualCanonicalizing() ignores nested list order')->toEqualCanonicalizing([
            'b' => [[1, 2], [3, 4]],
            'a' => [1, 2],
        ]);
    }

    #[Test]
    public function toEqualCanonicalizingReordersIntsBeyondFloatPrecision(): void
    {
        $a = 9_007_199_254_740_993;
        $b = 9_007_199_254_740_992;

        expect([$a, $b])->because('toEqualCanonicalizing() reorders ints beyond float precision')->toEqualCanonicalizing([$b, $a]);
        expect([$a, $a])->because('toEqualCanonicalizing() reorders ints beyond float precision')->not()->toEqualCanonicalizing([$b, $a]);
    }

    #[Test]
    public function toEqualCanonicalizingKeepsAssociativeKeys(): void
    {
        expect(['x' => 1, 'y' => 2])->because('toEqualCanonicalizing() keeps associative keys')->toEqualCanonicalizing(['y' => 2, 'x' => 1]);
        expect(['x' => 1])->because('toEqualCanonicalizing() keeps associative keys')->not()->toEqualCanonicalizing(['y' => 1]);
    }

    #[Test]
    public function toEqualCanonicalizingDelegatesToDeepEquality(): void
    {
        expect(1)->because('toEqualCanonicalizing() delegates to deep equality')->toEqualCanonicalizing(1.0);
        expect([1, 'a'])->because('toEqualCanonicalizing() delegates to deep equality')->toEqualCanonicalizing(['a', 1.0]);
    }

    #[Test]
    public function toEqualCanonicalizingKeepsListOrderInsideObjectProperties(): void
    {
        $subject = (object) ['values' => [2, 1]];
        $expected = (object) ['values' => [1, 2]];

        expect($subject)
            ->because('canonicalization MUST stop at an object boundary')
            ->not()->toEqualCanonicalizing($expected);
    }

    #[Test]
    public function toEqualCanonicalizingFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect([1, 2])->toEqualCanonicalizing([1, 2, 3]),
        );

        expect($detail->message)->because('toEqualCanonicalizing() fails')->toBe('Expected [1, 2] to equal (canonicalizing) [1, 2, 3].');
        expect($detail->expected)->because('toEqualCanonicalizing() fails')->toBe('[1, 2, 3]');
    }

    #[Test]
    public function notToEqualCanonicalizing(): void
    {
        expect([1, 2])->because('not()->toEqual() canonicalizing')->not()->toEqualCanonicalizing([1, 2, 3]);
    }

    #[Test]
    public function toEqualCanonicalizingOrdersCyclicObjectsByState(): void
    {
        $alpha = new CanonicalNode('alpha');
        $alpha->next = $alpha;
        $beta = new CanonicalNode('beta');
        $beta->next = $beta;

        expect([$beta, $alpha])
            ->because('canonical object ordering terminates on cycles')
            ->toEqualCanonicalizing([$alpha, $beta]);
    }

    #[Test]
    public function toEqualCanonicalizingOrdersIdentityOnlyValues(): void
    {
        $firstClosure = static fn(): string => 'first';
        $secondClosure = static fn(): string => 'second';
        $firstStream = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($firstStream));
        $secondStream = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($secondStream));

        expect([$secondClosure, $firstClosure])
            ->because('canonical closure ordering uses object identity')
            ->toEqualCanonicalizing([$firstClosure, $secondClosure]);
        expect([$secondStream, $firstStream])
            ->because('canonical resource ordering uses resource identity')
            ->toEqualCanonicalizing([$firstStream, $secondStream]);
    }
}
