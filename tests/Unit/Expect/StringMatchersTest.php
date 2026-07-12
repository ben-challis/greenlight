<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class StringMatchersTest
{
    #[Test]
    public function toMatchPasses(): void
    {
        Expect::that('greenlight-42')->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('abc')->toMatch('/\d+/'),
        );

        Expect::that($detail->message)->toBe("Expected 'abc' to match /\\d+/.");
        Expect::that($detail->expected)->toBe('/\d+/');
    }

    #[Test]
    public function notToMatch(): void
    {
        Expect::that('abc')->not()->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(123)->toMatch('/\d+/'),
        );

        Expect::that($detail->message)->toBe('toMatch() requires a string subject, got int.');
    }

    #[Test]
    public function toMatchRejectsInvalidPatterns(): void
    {
        Expect::that(static fn() => Expect::that('abc')->toMatch('not a pattern'))
            ->toThrow(\InvalidArgumentException::class, matching: '/invalid regular expression/');
    }

    #[Test]
    public function toStartWithPasses(): void
    {
        Expect::that('greenlight')->toStartWith('green');
        Expect::that('greenlight')->toStartWith('');
    }

    #[Test]
    public function toStartWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toStartWith('light'),
        );

        Expect::that($detail->message)->toBe("Expected 'greenlight' to start with 'light'.");
    }

    #[Test]
    public function notToStartWith(): void
    {
        Expect::that('greenlight')->not()->toStartWith('light');
    }

    #[Test]
    public function toStartWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['green'])->toStartWith('green'),
        );

        Expect::that($detail->message)->toBe('toStartWith() requires a string subject, got array.');
    }

    #[Test]
    public function toEndWithPasses(): void
    {
        Expect::that('greenlight')->toEndWith('light');
    }

    #[Test]
    public function toEndWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toEndWith('green'),
        );

        Expect::that($detail->message)->toBe("Expected 'greenlight' to end with 'green'.");
    }

    #[Test]
    public function notToEndWith(): void
    {
        Expect::that('greenlight')->not()->toEndWith('green');
    }

    #[Test]
    public function toEndWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(null)->toEndWith('x'),
        );

        Expect::that($detail->message)->toBe('toEndWith() requires a string subject, got null.');
    }

    #[Test]
    public function toHaveLengthPasses(): void
    {
        Expect::that('abc')->toHaveLength(3);
        Expect::that('')->toHaveLength(0);
        Expect::that([1, 2])->toHaveLength(2);
        Expect::that(new \ArrayObject([1]))->toHaveLength(1);
    }

    #[Test]
    public function toHaveLengthCountsCodePointsNotBytes(): void
    {
        Expect::that('héllo')->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthFallsBackToBytesForInvalidUtf8(): void
    {
        Expect::that("\xC3\x28")->toHaveLength(2);
    }

    #[Test]
    public function toHaveLengthFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('abc')->toHaveLength(5),
        );

        Expect::that($detail->message)->toBe("Expected 'abc' (length 3) to have length 5.");
        Expect::that($detail->expected)->toBe('length 5');
    }

    #[Test]
    public function notToHaveLength(): void
    {
        Expect::that('abc')->not()->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->toHaveLength(2),
        );

        Expect::that($detail->message)->toBe('toHaveLength() requires a string, array or Countable subject, got int.');
    }
}
