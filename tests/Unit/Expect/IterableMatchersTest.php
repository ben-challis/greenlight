<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class IterableMatchersTest
{
    #[Test]
    public function toContainFindsSubstrings(): void
    {
        expect('greenlight')->because('toContain() finds substrings')->toContain('light');
    }

    #[Test]
    public function toContainFailsOnMissingSubstring(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('greenlight')->toContain('dark'),
        );

        expect($detail->message)->because('toContain() fails on missing substring')->toBe("Expected 'greenlight' to contain 'dark'.");
    }

    #[Test]
    public function notToContainSubstring(): void
    {
        expect('greenlight')->because('not()->toContain() substring')->not()->toContain('dark');
    }

    #[Test]
    public function toContainFindsIterableMembersByIdentity(): void
    {
        expect([1, 2, 3])->because('toContain() finds iterable members by identity')->toContain(2);
        expect($this->numbers())->because('toContain() finds iterable members by identity')->toContain(2);
    }

    #[Test]
    public function toContainFailsOnMissingMember(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect([1, 2])->toContain(5),
        );

        expect($detail->message)->because('toContain() fails on missing member')->toBe('Expected [1, 2] to contain 5.');
    }

    #[Test]
    public function notToContainMemberUsesIdentity(): void
    {
        expect([1, 2])->because('not()->toContain() member uses identity')->not()->toContain(5);
        expect(['1'])->because('not()->toContain() member uses identity')->not()->toContain(1);
    }

    #[Test]
    public function toContainGuardsTheSubjectTypeEvenWhenNegated(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(42)->not()->toContain(4), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toContain() guards the subject type even when negated')
            ->toBe('toContain() requires a string or iterable subject. The subject type is int.');
    }

    #[Test]
    public function toContainGuardsTheNeedleTypeForStringSubjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('greenlight')->toContain(4), // @phpstan-ignore greenlight.toContain.needleType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toContain() guards the needle type for string subjects')
            ->toBe('toContain() requires a string needle for a string subject. The needle type is int.');
    }

    #[Test]
    public function toHaveCountPasses(): void
    {
        expect([1, 2])->because('toHaveCount() passes')->toHaveCount(2);
        expect(new \ArrayObject([1, 2, 3]))->because('toHaveCount() passes')->toHaveCount(3);
        expect($this->numbers())->because('toHaveCount() passes')->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect([1, 2])->toHaveCount(3),
        );

        expect($detail->message)->because('toHaveCount() fails')->toBe('Expected [1, 2] with count 2 to have count 3.');
        expect($detail->expected)->because('toHaveCount() fails')->toBe('count 3');
    }

    #[Test]
    public function notToHaveCount(): void
    {
        expect([1, 2])->because('not() to have count')->not()->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('12')->toHaveCount(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toHaveCount() guards the subject type')
            ->toBe('toHaveCount() requires a countable or traversable subject. The subject type is string.');
    }

    #[Test]
    public function toHaveKeyPasses(): void
    {
        expect(['a' => 1])->because('toHaveKey() passes')->toHaveKey('a');
        expect(['a' => null])->because('toHaveKey() passes')->toHaveKey('a');
        expect([10, 20])->because('toHaveKey() passes')->toHaveKey(1);
        expect(new \ArrayObject(['a' => 1]))->because('toHaveKey() passes')->toHaveKey('a');
    }

    #[Test]
    public function toHaveKeyFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(['a' => 1])->toHaveKey('b'),
        );

        expect($detail->message)->because('toHaveKey() fails')->toBe("Expected ['a' => 1] to have key 'b'.");
    }

    #[Test]
    public function notToHaveKey(): void
    {
        expect(['a' => 1])->because('not() to have key')->not()->toHaveKey('b');
    }

    #[Test]
    public function toHaveKeyGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('abc')->toHaveKey(0), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toHaveKey() guards the subject type')
            ->toBe('toHaveKey() requires an array or ArrayAccess subject. The subject type is string.');
    }

    #[Test]
    public function toBeEmptyPasses(): void
    {
        expect('')->because('toBeEmpty() passes')->toBeEmpty();
        expect([])->because('toBeEmpty() passes')->toBeEmpty();
        expect(new \ArrayObject())->because('toBeEmpty() passes')->toBeEmpty();
        expect($this->nothing())->because('toBeEmpty() passes')->toBeEmpty();
    }

    #[Test]
    public function toBeEmptyFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect([1])->toBeEmpty(),
        );

        expect($detail->message)->because('toBeEmpty() fails')->toBe('Expected [1] to be empty.');
        expect($detail->expected)->because('toBeEmpty() fails')->toBe('empty');
    }

    #[Test]
    public function toBeEmptyFailsOnNonEmptyStrings(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('x')->toBeEmpty(),
        );

        expect($detail->message)->because('toBeEmpty() fails on non empty strings')->toBe("Expected 'x' to be empty.");
    }

    #[Test]
    public function notToBeEmpty(): void
    {
        expect([1])->because('not()->toBe() empty')->not()->toBeEmpty();
        expect('x')->because('not()->toBe() empty')->not()->toBeEmpty();
    }

    #[Test]
    public function toBeEmptyGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(0)->toBeEmpty(), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toBeEmpty() guards the subject type')
            ->toBe('toBeEmpty() requires a string, array, Countable, or iterable subject. The subject type is int.');
    }

    #[Test]
    public function toBeOneOfPasses(): void
    {
        expect(2)->because('toBeOneOf() passes')->toBeOneOf(1, 2, 3);
        expect('b')->because('toBeOneOf() passes')->toBeOneOf('a', 'b');
    }

    #[Test]
    public function toBeOneOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(4)->toBeOneOf(1, 2),
        );

        expect($detail->message)->because('toBeOneOf() fails')->toBe('Expected 4 to be one of [1, 2].');
        expect($detail->expected)->because('toBeOneOf() fails')->toBe('one of [1, 2]');
    }

    #[Test]
    public function toBeOneOfUsesIdentity(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('1')->toBeOneOf(1, 2),
        );

        expect($detail->message)->because('toBeOneOf() uses identity')->toBe("Expected '1' to be one of [1, 2].");
    }

    #[Test]
    public function notToBeOneOf(): void
    {
        expect(4)->because('not()->toBe() one of')->not()->toBeOneOf(1, 2);
        expect('1')->because('not()->toBe() one of')->not()->toBeOneOf(1, 2);
    }

    #[Test]
    public function toBeInPasses(): void
    {
        expect(2)
            ->because('toBeIn() passes')
            ->toBeIn([1, 2, 3])
            ->toBeIn($this->numbers());
    }

    #[Test]
    public function toBeInFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(5)->toBeIn([1, 2]),
        );

        expect($detail->message)->because('toBeIn() fails')->toBe('Expected 5 to be in [1, 2].');
        expect($detail->expected)->because('toBeIn() fails')->toBe('in [1, 2]');
    }

    #[Test]
    public function toBeInUsesIdentity(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(1)->toBeIn(['1']),
        );

        expect($detail->message)->because('toBeIn() uses identity')->toBe("Expected 1 to be in ['1'].");
    }

    #[Test]
    public function notToBeIn(): void
    {
        expect(5)->because('not()->toBe() in')->not()->toBeIn([1, 2]);
    }

    #[Test]
    public function toContainSubsetPasses(): void
    {
        expect(['a' => 1, 'b' => 2])
            ->because('toContainSubset() passes')
            ->toContainSubset(['a' => 1])
            ->toContainSubset([]);
    }

    #[Test]
    public function toContainSubsetMatchesNestedArraysPartially(): void
    {
        expect([
            'user' => ['name' => 'Ada', 'address' => ['city' => 'Oslo', 'zip' => '123']],
            'active' => true,
        ])->because('toContainSubset() matches nested arrays partially')->toContainSubset([
            'user' => ['address' => ['city' => 'Oslo']],
        ]);
    }

    #[Test]
    public function toContainSubsetComparesValuesWithEquality(): void
    {
        expect(['a' => 1])->because('toContainSubset() compares values with equality')->toContainSubset(['a' => 1.0]);
    }

    #[Test]
    public function toContainSubsetTreatsANullValuedKeyAsPresent(): void
    {
        expect(['optional' => null])
            ->because('subset matching MUST distinguish a null-valued key from a missing key')
            ->toContainSubset(['optional' => null]);
    }

    #[Test]
    public function toContainSubsetFailsOnMissingKeyWithPath(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(['user' => ['address' => ['city' => 'Oslo']]])
                ->toContainSubset(['user' => ['address' => ['country' => 'NO']]]),
        );

        expect($detail->message)->because('toContainSubset() fails on missing key with path')->toBe(
            "Expected ['user' => ['address' => ['city' => 'Oslo']]] to contain the subset "
            . "['user' => ['address' => ['country' => 'NO']]] (missing key 'user.address.country').",
        );
        expect($detail->expected)->because('toContainSubset() fails on missing key with path')->toBe("['user' => ['address' => ['country' => 'NO']]]");
        expect($detail->actual)->because('toContainSubset() fails on missing key with path')->toBe("['user' => ['address' => ['city' => 'Oslo']]]");
    }

    #[Test]
    public function toContainSubsetFailsOnMismatchedValueWithPath(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(['user' => ['name' => 'Ada']])
                ->toContainSubset(['user' => ['name' => 'Bob']]),
        );

        expect($detail->message)->because('toContainSubset() fails on mismatched value with path')->toBe(
            "Expected ['user' => ['name' => 'Ada']] to contain the subset "
            . "['user' => ['name' => 'Bob']] (mismatched value at key 'user.name').",
        );
    }

    #[Test]
    public function notToContainSubset(): void
    {
        expect(['a' => 1])
            ->because('not()->toContain() subset')
            ->not()
            ->toContainSubset(['a' => 2])
            ->not()
            ->toContainSubset(['b' => 1]);
    }

    #[Test]
    public function toContainSubsetGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('x')->toContainSubset(['a' => 1]), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toContainSubset() guards the subject type')
            ->toBe('toContainSubset() requires an array subject. The subject type is string.');
    }

    /**
     * @return \Generator<int, int>
     */
    private function nothing(): \Generator
    {
        yield from [];
    }

    /**
     * @return \Generator<int, int>
     */
    private function numbers(): \Generator
    {
        yield 1;
        yield 2;
        yield 3;
    }
}
