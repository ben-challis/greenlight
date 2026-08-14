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
        Expect::that('greenlight-42')->because('toMatch() passes')->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('abc')->toMatch('/\d+/'),
        );

        Expect::that($detail->message)->because('toMatch() fails')->toBe("Expected 'abc' to match /\\d+/.");
        Expect::that($detail->expected)->because('toMatch() fails')->toBe('/\d+/');
    }

    #[Test]
    public function notToMatch(): void
    {
        Expect::that('abc')->because('not() to match')->not()->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(123)->toMatch('/\d+/'),
        );

        Expect::that($detail->message)->because('toMatch() guards the subject type')
            ->toBe('toMatch() requires a string subject. The subject type is int.');
    }

    #[Test]
    public function toMatchRejectsInvalidPatterns(): void
    {
        Expect::that(static fn() => Expect::that('abc')->toMatch('not a pattern'))->because('toMatch() rejects invalid patterns') // @phpstan-ignore greenlight.expectationArgument.pattern (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'The pattern for toMatch() is an invalid regular expression: not a pattern '
                    . '(preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte)',
            );
    }

    #[Test]
    public function toStartWithPasses(): void
    {
        Expect::that('greenlight')->because('toStartWith() passes')->toStartWith('green');
        Expect::that('greenlight')->because('toStartWith() passes')->toStartWith('');
    }

    #[Test]
    public function toStartWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toStartWith('light'),
        );

        Expect::that($detail->message)->because('toStartWith() fails')->toBe("Expected 'greenlight' to start with 'light'.");
    }

    #[Test]
    public function notToStartWith(): void
    {
        Expect::that('greenlight')->because('not() to start with')->not()->toStartWith('light');
    }

    #[Test]
    public function toStartWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['green'])->toStartWith('green'),
        );

        Expect::that($detail->message)->because('toStartWith() guards the subject type')
            ->toBe('toStartWith() requires a string subject. The subject type is array.');
    }

    #[Test]
    public function toEndWithPasses(): void
    {
        Expect::that('greenlight')->because('toEndWith() passes')->toEndWith('light');
    }

    #[Test]
    public function toEndWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('greenlight')->toEndWith('green'),
        );

        Expect::that($detail->message)->because('toEndWith() fails')->toBe("Expected 'greenlight' to end with 'green'.");
    }

    #[Test]
    public function notToEndWith(): void
    {
        Expect::that('greenlight')->because('not() to end with')->not()->toEndWith('green');
    }

    #[Test]
    public function toEndWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(null)->toEndWith('x'),
        );

        Expect::that($detail->message)->because('toEndWith() guards the subject type')
            ->toBe('toEndWith() requires a string subject. The subject type is null.');
    }

    #[Test]
    public function toHaveLengthPasses(): void
    {
        Expect::that('abc')->because('toHaveLength() passes')->toHaveLength(3);
        Expect::that('')->because('toHaveLength() passes')->toHaveLength(0);
        Expect::that([1, 2])->because('toHaveLength() passes')->toHaveLength(2);
        Expect::that(new \ArrayObject([1]))->because('toHaveLength() passes')->toHaveLength(1);
    }

    #[Test]
    public function toHaveLengthCountsCodePointsNotBytes(): void
    {
        Expect::that('héllo')->because('toHaveLength() counts code points not bytes')->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthFallsBackToBytesForInvalidUtf8(): void
    {
        Expect::that("\xC3\x28")->because('toHaveLength() counts bytes for invalid UTF-8')->toHaveLength(2);
    }

    #[Test]
    public function toHaveLengthFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('abc')->toHaveLength(5),
        );

        Expect::that($detail->message)->because('toHaveLength() fails')->toBe("Expected 'abc' (length 3) to have length 5.");
        Expect::that($detail->expected)->because('toHaveLength() fails')->toBe('length 5');
    }

    #[Test]
    public function notToHaveLength(): void
    {
        Expect::that('abc')->because('not() to have length')->not()->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->toHaveLength(2),
        );

        Expect::that($detail->message)->because('toHaveLength() guards the subject type')
            ->toBe('toHaveLength() requires a string, array, or Countable subject. The subject type is int.');
    }
}
