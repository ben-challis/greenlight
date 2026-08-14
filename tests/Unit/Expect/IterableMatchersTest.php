<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class IterableMatchersTest
{
    #[Test]
    public function toContainFindsSubstrings(): void
    {
        Expect::that('greenlight')->because('toContain() finds substrings')->toContain('light');
    }

    #[Test]
    public function toContainFailsOnMissingSubstring(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toContain('dark'),
        );

        Expect::that($detail->message)->because('toContain() fails on missing substring')->toBe("Expected 'greenlight' to contain 'dark'.");
    }

    #[Test]
    public function notToContainSubstring(): void
    {
        Expect::that('greenlight')->because('not()->toContain() substring')->not()->toContain('dark');
    }

    #[Test]
    public function toContainFindsIterableMembersByIdentity(): void
    {
        Expect::that([1, 2, 3])->because('toContain() finds iterable members by identity')->toContain(2);
        Expect::that($this->numbers())->because('toContain() finds iterable members by identity')->toContain(2);
    }

    #[Test]
    public function toContainFailsOnMissingMember(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([1, 2])->toContain(5),
        );

        Expect::that($detail->message)->because('toContain() fails on missing member')->toBe('Expected [1, 2] to contain 5.');
    }

    #[Test]
    public function notToContainMemberUsesIdentity(): void
    {
        Expect::that([1, 2])->because('not()->toContain() member uses identity')->not()->toContain(5);
        Expect::that(['1'])->because('not()->toContain() member uses identity')->not()->toContain(1);
    }

    #[Test]
    public function toContainGuardsTheSubjectTypeEvenWhenNegated(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->not()->toContain(4), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toContain() guards the subject type even when negated')
            ->toBe('toContain() requires a string or iterable subject. The subject type is int.');
    }

    #[Test]
    public function toContainGuardsTheNeedleTypeForStringSubjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toContain(4), // @phpstan-ignore greenlight.toContain.needleType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toContain() guards the needle type for string subjects')
            ->toBe('toContain() requires a string needle for a string subject. The needle type is int.');
    }

    #[Test]
    public function toHaveCountPasses(): void
    {
        Expect::that([1, 2])->because('toHaveCount() passes')->toHaveCount(2);
        Expect::that(new \ArrayObject([1, 2, 3]))->because('toHaveCount() passes')->toHaveCount(3);
        Expect::that($this->numbers())->because('toHaveCount() passes')->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([1, 2])->toHaveCount(3),
        );

        Expect::that($detail->message)->because('toHaveCount() fails')->toBe('Expected [1, 2] with count 2 to have count 3.');
        Expect::that($detail->expected)->because('toHaveCount() fails')->toBe('count 3');
    }

    #[Test]
    public function notToHaveCount(): void
    {
        Expect::that([1, 2])->because('not() to have count')->not()->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('12')->toHaveCount(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toHaveCount() guards the subject type')
            ->toBe('toHaveCount() requires a countable or traversable subject. The subject type is string.');
    }

    #[Test]
    public function toHaveKeyPasses(): void
    {
        Expect::that(['a' => 1])->because('toHaveKey() passes')->toHaveKey('a');
        Expect::that(['a' => null])->because('toHaveKey() passes')->toHaveKey('a');
        Expect::that([10, 20])->because('toHaveKey() passes')->toHaveKey(1);
        Expect::that(new \ArrayObject(['a' => 1]))->because('toHaveKey() passes')->toHaveKey('a');
    }

    #[Test]
    public function toHaveKeyFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['a' => 1])->toHaveKey('b'),
        );

        Expect::that($detail->message)->because('toHaveKey() fails')->toBe("Expected ['a' => 1] to have key 'b'.");
    }

    #[Test]
    public function notToHaveKey(): void
    {
        Expect::that(['a' => 1])->because('not() to have key')->not()->toHaveKey('b');
    }

    #[Test]
    public function toHaveKeyGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('abc')->toHaveKey(0), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toHaveKey() guards the subject type')
            ->toBe('toHaveKey() requires an array or ArrayAccess subject. The subject type is string.');
    }

    #[Test]
    public function toBeEmptyPasses(): void
    {
        Expect::that('')->because('toBeEmpty() passes')->toBeEmpty();
        Expect::that([])->because('toBeEmpty() passes')->toBeEmpty();
        Expect::that(new \ArrayObject())->because('toBeEmpty() passes')->toBeEmpty();
        Expect::that($this->nothing())->because('toBeEmpty() passes')->toBeEmpty();
    }

    #[Test]
    public function toBeEmptyFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([1])->toBeEmpty(),
        );

        Expect::that($detail->message)->because('toBeEmpty() fails')->toBe('Expected [1] to be empty.');
        Expect::that($detail->expected)->because('toBeEmpty() fails')->toBe('empty');
    }

    #[Test]
    public function toBeEmptyFailsOnNonEmptyStrings(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('x')->toBeEmpty(),
        );

        Expect::that($detail->message)->because('toBeEmpty() fails on non empty strings')->toBe("Expected 'x' to be empty.");
    }

    #[Test]
    public function notToBeEmpty(): void
    {
        Expect::that([1])->because('not()->toBe() empty')->not()->toBeEmpty();
        Expect::that('x')->because('not()->toBe() empty')->not()->toBeEmpty();
    }

    #[Test]
    public function toBeEmptyGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(0)->toBeEmpty(), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toBeEmpty() guards the subject type')
            ->toBe('toBeEmpty() requires a string, array, Countable, or iterable subject. The subject type is int.');
    }

    #[Test]
    public function toBeOneOfPasses(): void
    {
        Expect::that(2)->because('toBeOneOf() passes')->toBeOneOf(1, 2, 3);
        Expect::that('b')->because('toBeOneOf() passes')->toBeOneOf('a', 'b');
    }

    #[Test]
    public function toBeOneOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(4)->toBeOneOf(1, 2),
        );

        Expect::that($detail->message)->because('toBeOneOf() fails')->toBe('Expected 4 to be one of [1, 2].');
        Expect::that($detail->expected)->because('toBeOneOf() fails')->toBe('one of [1, 2]');
    }

    #[Test]
    public function toBeOneOfUsesIdentity(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('1')->toBeOneOf(1, 2),
        );

        Expect::that($detail->message)->because('toBeOneOf() uses identity')->toBe("Expected '1' to be one of [1, 2].");
    }

    #[Test]
    public function notToBeOneOf(): void
    {
        Expect::that(4)->because('not()->toBe() one of')->not()->toBeOneOf(1, 2);
        Expect::that('1')->because('not()->toBe() one of')->not()->toBeOneOf(1, 2);
    }

    #[Test]
    public function toBeInPasses(): void
    {
        Expect::that(2)->because('toBeIn() passes')->toBeIn([1, 2, 3]);
        Expect::that(2)->because('toBeIn() passes')->toBeIn($this->numbers());
    }

    #[Test]
    public function toBeInFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(5)->toBeIn([1, 2]),
        );

        Expect::that($detail->message)->because('toBeIn() fails')->toBe('Expected 5 to be in [1, 2].');
        Expect::that($detail->expected)->because('toBeIn() fails')->toBe('in [1, 2]');
    }

    #[Test]
    public function toBeInUsesIdentity(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(1)->toBeIn(['1']),
        );

        Expect::that($detail->message)->because('toBeIn() uses identity')->toBe("Expected 1 to be in ['1'].");
    }

    #[Test]
    public function notToBeIn(): void
    {
        Expect::that(5)->because('not()->toBe() in')->not()->toBeIn([1, 2]);
    }

    #[Test]
    public function toContainSubsetPasses(): void
    {
        Expect::that(['a' => 1, 'b' => 2])->because('toContainSubset() passes')->toContainSubset(['a' => 1]);
        Expect::that(['a' => 1, 'b' => 2])->because('toContainSubset() passes')->toContainSubset([]);
    }

    #[Test]
    public function toContainSubsetMatchesNestedArraysPartially(): void
    {
        Expect::that([
            'user' => ['name' => 'Ada', 'address' => ['city' => 'Oslo', 'zip' => '123']],
            'active' => true,
        ])->because('toContainSubset() matches nested arrays partially')->toContainSubset([
            'user' => ['address' => ['city' => 'Oslo']],
        ]);
    }

    #[Test]
    public function toContainSubsetComparesValuesWithEquality(): void
    {
        Expect::that(['a' => 1])->because('toContainSubset() compares values with equality')->toContainSubset(['a' => 1.0]);
    }

    #[Test]
    public function toContainSubsetTreatsANullValuedKeyAsPresent(): void
    {
        Expect::that(['optional' => null])
            ->because('subset matching MUST distinguish a null-valued key from a missing key')
            ->toContainSubset(['optional' => null]);
    }

    #[Test]
    public function toContainSubsetFailsOnMissingKeyWithPath(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['user' => ['address' => ['city' => 'Oslo']]])
                ->toContainSubset(['user' => ['address' => ['country' => 'NO']]]),
        );

        Expect::that($detail->message)->because('toContainSubset() fails on missing key with path')->toBe(
            "Expected ['user' => ['address' => ['city' => 'Oslo']]] to contain the subset "
            . "['user' => ['address' => ['country' => 'NO']]] (missing key 'user.address.country').",
        );
        Expect::that($detail->expected)->because('toContainSubset() fails on missing key with path')->toBe("['user' => ['address' => ['country' => 'NO']]]");
        Expect::that($detail->actual)->because('toContainSubset() fails on missing key with path')->toBe("['user' => ['address' => ['city' => 'Oslo']]]");
    }

    #[Test]
    public function toContainSubsetFailsOnMismatchedValueWithPath(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['user' => ['name' => 'Ada']])
                ->toContainSubset(['user' => ['name' => 'Bob']]),
        );

        Expect::that($detail->message)->because('toContainSubset() fails on mismatched value with path')->toBe(
            "Expected ['user' => ['name' => 'Ada']] to contain the subset "
            . "['user' => ['name' => 'Bob']] (mismatched value at key 'user.name').",
        );
    }

    #[Test]
    public function notToContainSubset(): void
    {
        Expect::that(['a' => 1])->because('not()->toContain() subset')->not()->toContainSubset(['a' => 2]);
        Expect::that(['a' => 1])->because('not()->toContain() subset')->not()->toContainSubset(['b' => 1]);
    }

    #[Test]
    public function toContainSubsetGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('x')->toContainSubset(['a' => 1]), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toContainSubset() guards the subject type')
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
