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
        new Expect()->that('greenlight')->toContain('light');
    }

    #[Test]
    public function toContainFailsOnMissingSubstring(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('greenlight')->toContain('dark'),
        );

        new Expect()->that($detail->message)->toBe("Expected 'greenlight' to contain 'dark'.");
    }

    #[Test]
    public function notToContainSubstring(): void
    {
        new Expect()->that('greenlight')->not()->toContain('dark');
    }

    #[Test]
    public function toContainFindsIterableMembersByIdentity(): void
    {
        new Expect()->that([1, 2, 3])->toContain(2);
        new Expect()->that($this->numbers())->toContain(2);
    }

    #[Test]
    public function toContainFailsOnMissingMember(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that([1, 2])->toContain(5),
        );

        new Expect()->that($detail->message)->toBe('Expected [1, 2] to contain 5.');
    }

    #[Test]
    public function notToContainMemberUsesIdentity(): void
    {
        new Expect()->that([1, 2])->not()->toContain(5);
        new Expect()->that(['1'])->not()->toContain(1);
    }

    #[Test]
    public function toContainGuardsTheSubjectTypeEvenWhenNegated(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(42)->not()->toContain(4),
        );

        new Expect()->that($detail->message)->toBe('toContain() requires a string or iterable subject, got int.');
    }

    #[Test]
    public function toContainGuardsTheNeedleTypeForStringSubjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('greenlight')->toContain(4),
        );

        new Expect()->that($detail->message)->toBe('toContain() on a string subject requires a string needle, got int.');
    }

    #[Test]
    public function toHaveCountPasses(): void
    {
        new Expect()->that([1, 2])->toHaveCount(2);
        new Expect()->that(new \ArrayObject([1, 2, 3]))->toHaveCount(3);
        new Expect()->that($this->numbers())->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that([1, 2])->toHaveCount(3),
        );

        $expect = new Expect();
        $expect->that($detail->message)->toBe('Expected [1, 2] with count 2 to have count 3.');
        $expect->that($detail->expected)->toBe('count 3');
    }

    #[Test]
    public function notToHaveCount(): void
    {
        new Expect()->that([1, 2])->not()->toHaveCount(3);
    }

    #[Test]
    public function toHaveCountGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('12')->toHaveCount(2),
        );

        new Expect()->that($detail->message)->toBe('toHaveCount() requires a countable or traversable subject, got string.');
    }

    #[Test]
    public function toHaveKeyPasses(): void
    {
        new Expect()->that(['a' => 1])->toHaveKey('a');
        new Expect()->that(['a' => null])->toHaveKey('a');
        new Expect()->that([10, 20])->toHaveKey(1);
        new Expect()->that(new \ArrayObject(['a' => 1]))->toHaveKey('a');
    }

    #[Test]
    public function toHaveKeyFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(['a' => 1])->toHaveKey('b'),
        );

        new Expect()->that($detail->message)->toBe("Expected ['a' => 1] to have key 'b'.");
    }

    #[Test]
    public function notToHaveKey(): void
    {
        new Expect()->that(['a' => 1])->not()->toHaveKey('b');
    }

    #[Test]
    public function toHaveKeyGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('abc')->toHaveKey(0),
        );

        new Expect()->that($detail->message)->toBe('toHaveKey() requires an array or ArrayAccess subject, got string.');
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
