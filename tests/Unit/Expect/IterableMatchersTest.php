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
