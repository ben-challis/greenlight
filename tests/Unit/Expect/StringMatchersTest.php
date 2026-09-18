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
        Expect::value('greenlight-42')->because('toMatch() passes')->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('abc')->toMatch('/\d+/'),
        );

        Expect::value($detail->message)->because('toMatch() fails')->toBe("Expected 'abc' to match /\\d+/.");
        Expect::value($detail->expected)->because('toMatch() fails')->toBe('/\d+/');
    }

    #[Test]
    public function notToMatch(): void
    {
        Expect::value('abc')->because('not() to match')->not()->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value(123)->toMatch('/\d+/'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::value($detail->message)->because('toMatch() guards the subject type')
            ->toBe('toMatch() requires a string subject. The subject type is int.');
    }

    #[Test]
    public function toMatchRejectsInvalidPatterns(): void
    {
        Expect::calling(static fn() => Expect::value('abc')->toMatch('not a pattern'))->because('toMatch() rejects invalid patterns') // @phpstan-ignore greenlight.expectationArgument.pattern (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'The pattern for toMatch() is an invalid regular expression: not a pattern '
                    . '(preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte)',
            );
    }

    #[Test]
    public function toStartWithPasses(): void
    {
        Expect::value('greenlight')->because('toStartWith() passes')->toStartWith('green');
        Expect::value('greenlight')->because('toStartWith() passes')->toStartWith('');
    }

    #[Test]
    public function toStartWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('greenlight')->toStartWith('light'),
        );

        Expect::value($detail->message)->because('toStartWith() fails')->toBe("Expected 'greenlight' to start with 'light'.");
    }

    #[Test]
    public function notToStartWith(): void
    {
        Expect::value('greenlight')->because('not() to start with')->not()->toStartWith('light');
    }

    #[Test]
    public function toStartWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value(['green'])->toStartWith('green'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::value($detail->message)->because('toStartWith() guards the subject type')
            ->toBe('toStartWith() requires a string subject. The subject type is array.');
    }

    #[Test]
    public function toEndWithPasses(): void
    {
        Expect::value('greenlight')->because('toEndWith() passes')->toEndWith('light');
    }

    #[Test]
    public function toEndWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('greenlight')->toEndWith('green'),
        );

        Expect::value($detail->message)->because('toEndWith() fails')->toBe("Expected 'greenlight' to end with 'green'.");
    }

    #[Test]
    public function notToEndWith(): void
    {
        Expect::value('greenlight')->because('not() to end with')->not()->toEndWith('green');
    }

    #[Test]
    public function toEndWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value(null)->toEndWith('x'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::value($detail->message)->because('toEndWith() guards the subject type')
            ->toBe('toEndWith() requires a string subject. The subject type is null.');
    }

    #[Test]
    public function toHaveLengthPasses(): void
    {
        Expect::value('abc')->because('toHaveLength() passes')->toHaveLength(3);
        Expect::value('')->because('toHaveLength() passes')->toHaveLength(0);
        Expect::value([1, 2])->because('toHaveLength() passes')->toHaveLength(2);
        Expect::value(new \ArrayObject([1]))->because('toHaveLength() passes')->toHaveLength(1);
    }

    #[Test]
    public function toHaveLengthCountsCodePointsNotBytes(): void
    {
        Expect::value('héllo')->because('toHaveLength() counts code points not bytes')->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthFallsBackToBytesForInvalidUtf8(): void
    {
        Expect::value("\xC3\x28")->because('toHaveLength() counts bytes for invalid UTF-8')->toHaveLength(2);
    }

    #[Test]
    public function toHaveLengthFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value('abc')->toHaveLength(5),
        );

        Expect::value($detail->message)->because('toHaveLength() fails')->toBe("Expected 'abc' (length 3) to have length 5.");
        Expect::value($detail->expected)->because('toHaveLength() fails')->toBe('length 5');
    }

    #[Test]
    public function notToHaveLength(): void
    {
        Expect::value('abc')->because('not() to have length')->not()->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value(42)->toHaveLength(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::value($detail->message)->because('toHaveLength() guards the subject type')
            ->toBe('toHaveLength() requires a string, array, or Countable subject. The subject type is int.');
    }
}
