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
        Expect::that('greenlight')->toContain('light');
    }

    #[Test]
    public function toContainFailsOnMissingSubstring(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toContain('dark'),
        );

        Expect::that($detail->message)->toBe("Expected 'greenlight' to contain 'dark'.");
    }

    #[Test]
    public function notToContainSubstring(): void
    {
        Expect::that('greenlight')->not()->toContain('dark');
    }

    #[Test]
    public function toContainFindsIterableMembersByIdentity(): void
    {
        Expect::that([1, 2, 3])->toContain(2);
        Expect::that($this->numbers())->toContain(2);
    }

    #[Test]
    public function toContainFailsOnMissingMember(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([1, 2])->toContain(5),
        );

        Expect::that($detail->message)->toBe('Expected [1, 2] to contain 5.');
    }

    #[Test]
    public function notToContainMemberUsesIdentity(): void
    {
        Expect::that([1, 2])->not()->toContain(5);
        Expect::that(['1'])->not()->toContain(1);
    }

    #[Test]
    public function toContainGuardsTheSubjectTypeEvenWhenNegated(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->not()->toContain(4),
        );

        Expect::that($detail->message)->toBe('toContain() requires a string or iterable subject, got int.');
    }

    #[Test]
    public function toContainGuardsTheNeedleTypeForStringSubjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toContain(4),
        );

        Expect::that($detail->message)->toBe('toContain() on a string subject requires a string needle, got int.');
    }

    #[Test]
    public function toHaveCountPasses(): void
    {
        Expect::that([1, 2])->toHaveCount(2);
        Expect::that(new \ArrayObject([1, 2, 3]))->toHaveCount(3);
        Expect::that($this->numbers())->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([1, 2])->toHaveCount(3),
        );

        Expect::that($detail->message)->toBe('Expected [1, 2] with count 2 to have count 3.');
        Expect::that($detail->expected)->toBe('count 3');
    }

    #[Test]
    public function notToHaveCount(): void
    {
        Expect::that([1, 2])->not()->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('12')->toHaveCount(2),
        );

        Expect::that($detail->message)->toBe('toHaveCount() requires a countable or traversable subject, got string.');
    }

    #[Test]
    public function toHaveKeyPasses(): void
    {
        Expect::that(['a' => 1])->toHaveKey('a');
        Expect::that(['a' => null])->toHaveKey('a');
        Expect::that([10, 20])->toHaveKey(1);
        Expect::that(new \ArrayObject(['a' => 1]))->toHaveKey('a');
    }

    #[Test]
    public function toHaveKeyFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['a' => 1])->toHaveKey('b'),
        );

        Expect::that($detail->message)->toBe("Expected ['a' => 1] to have key 'b'.");
    }

    #[Test]
    public function notToHaveKey(): void
    {
        Expect::that(['a' => 1])->not()->toHaveKey('b');
    }

    #[Test]
    public function toHaveKeyGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('abc')->toHaveKey(0),
        );

        Expect::that($detail->message)->toBe('toHaveKey() requires an array or ArrayAccess subject, got string.');
    }

    #[Test]
    public function toBeEmptyPasses(): void
    {
        Expect::that('')->toBeEmpty();
        Expect::that([])->toBeEmpty();
        Expect::that(new \ArrayObject())->toBeEmpty();
        Expect::that($this->nothing())->toBeEmpty();
    }

    #[Test]
    public function toBeEmptyFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that([1])->toBeEmpty(),
        );

        Expect::that($detail->message)->toBe('Expected [1] to be empty.');
        Expect::that($detail->expected)->toBe('empty');
    }

    #[Test]
    public function toBeEmptyFailsOnNonEmptyStrings(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('x')->toBeEmpty(),
        );

        Expect::that($detail->message)->toBe("Expected 'x' to be empty.");
    }

    #[Test]
    public function notToBeEmpty(): void
    {
        Expect::that([1])->not()->toBeEmpty();
        Expect::that('x')->not()->toBeEmpty();
    }

    #[Test]
    public function toBeEmptyGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(0)->toBeEmpty(),
        );

        Expect::that($detail->message)->toBe('toBeEmpty() requires a string, array, Countable or iterable subject, got int.');
    }

    #[Test]
    public function toBeOneOfPasses(): void
    {
        Expect::that(2)->toBeOneOf(1, 2, 3);
        Expect::that('b')->toBeOneOf('a', 'b');
    }

    #[Test]
    public function toBeOneOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(4)->toBeOneOf(1, 2),
        );

        Expect::that($detail->message)->toBe('Expected 4 to be one of [1, 2].');
        Expect::that($detail->expected)->toBe('one of [1, 2]');
    }

    #[Test]
    public function toBeOneOfUsesIdentity(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('1')->toBeOneOf(1, 2),
        );

        Expect::that($detail->message)->toBe("Expected '1' to be one of [1, 2].");
    }

    #[Test]
    public function notToBeOneOf(): void
    {
        Expect::that(4)->not()->toBeOneOf(1, 2);
        Expect::that('1')->not()->toBeOneOf(1, 2);
    }

    #[Test]
    public function toBeInPasses(): void
    {
        Expect::that(2)->toBeIn([1, 2, 3]);
        Expect::that(2)->toBeIn($this->numbers());
    }

    #[Test]
    public function toBeInFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(5)->toBeIn([1, 2]),
        );

        Expect::that($detail->message)->toBe('Expected 5 to be in [1, 2].');
        Expect::that($detail->expected)->toBe('in [1, 2]');
    }

    #[Test]
    public function toBeInUsesIdentity(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(1)->toBeIn(['1']),
        );

        Expect::that($detail->message)->toBe("Expected 1 to be in ['1'].");
    }

    #[Test]
    public function notToBeIn(): void
    {
        Expect::that(5)->not()->toBeIn([1, 2]);
    }

    #[Test]
    public function toContainSubsetPasses(): void
    {
        Expect::that(['a' => 1, 'b' => 2])->toContainSubset(['a' => 1]);
        Expect::that(['a' => 1, 'b' => 2])->toContainSubset([]);
    }

    #[Test]
    public function toContainSubsetMatchesNestedArraysPartially(): void
    {
        Expect::that([
            'user' => ['name' => 'Ada', 'address' => ['city' => 'Oslo', 'zip' => '123']],
            'active' => true,
        ])->toContainSubset([
            'user' => ['address' => ['city' => 'Oslo']],
        ]);
    }

    #[Test]
    public function toContainSubsetComparesValuesWithEquality(): void
    {
        Expect::that(['a' => 1])->toContainSubset(['a' => 1.0]);
    }

    #[Test]
    public function toContainSubsetFailsOnMissingKeyWithPath(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['user' => ['address' => ['city' => 'Oslo']]])
                ->toContainSubset(['user' => ['address' => ['country' => 'NO']]]),
        );

        Expect::that($detail->message)->toBe(
            "Expected ['user' => ['address' => ['city' => 'Oslo']]] to contain the subset "
            . "['user' => ['address' => ['country' => 'NO']]] (missing key 'user.address.country').",
        );
        Expect::that($detail->expected)->toBe("['user' => ['address' => ['country' => 'NO']]]");
        Expect::that($detail->actual)->toBe("['user' => ['address' => ['city' => 'Oslo']]]");
    }

    #[Test]
    public function toContainSubsetFailsOnMismatchedValueWithPath(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['user' => ['name' => 'Ada']])
                ->toContainSubset(['user' => ['name' => 'Bob']]),
        );

        Expect::that($detail->message)->toBe(
            "Expected ['user' => ['name' => 'Ada']] to contain the subset "
            . "['user' => ['name' => 'Bob']] (mismatched value at key 'user.name').",
        );
    }

    #[Test]
    public function notToContainSubset(): void
    {
        Expect::that(['a' => 1])->not()->toContainSubset(['a' => 2]);
        Expect::that(['a' => 1])->not()->toContainSubset(['b' => 1]);
    }

    #[Test]
    public function toContainSubsetGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('x')->toContainSubset(['a' => 1]),
        );

        Expect::that($detail->message)->toBe('toContainSubset() requires an array subject, got string.');
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
