<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class StringMatchersTest
{
    #[Test]
    public function toMatchPasses(): void
    {
        expect('greenlight-42')->because('toMatch() passes')->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('abc')->toMatch('/\d+/'),
        );

        expect($detail->message)->because('toMatch() fails')->toBe("Expected 'abc' to match /\\d+/.");
        expect($detail->expected)->because('toMatch() fails')->toBe('/\d+/');
    }

    #[Test]
    public function notToMatch(): void
    {
        expect('abc')->because('not() to match')->not()->toMatch('/\d+/');
    }

    #[Test]
    public function toMatchGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(123)->toMatch('/\d+/'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toMatch() guards the subject type')
            ->toBe('toMatch() requires a string subject. The subject type is int.');
    }

    #[Test]
    public function toMatchRejectsInvalidPatterns(): void
    {
        expect()->calling(static fn() => expect('abc')->toMatch('not a pattern'))->because('toMatch() rejects invalid patterns') // @phpstan-ignore greenlight.expectationArgument.pattern (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'The pattern for toMatch() is an invalid regular expression: not a pattern '
                    . '(preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte)',
            );
    }

    #[Test]
    public function toStartWithPasses(): void
    {
        expect('greenlight')
            ->because('toStartWith() passes')
            ->toStartWith('green')
            ->because('toStartWith() passes')
            ->toStartWith('');
    }

    #[Test]
    public function toStartWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('greenlight')->toStartWith('light'),
        );

        expect($detail->message)->because('toStartWith() fails')->toBe("Expected 'greenlight' to start with 'light'.");
    }

    #[Test]
    public function notToStartWith(): void
    {
        expect('greenlight')->because('not() to start with')->not()->toStartWith('light');
    }

    #[Test]
    public function toStartWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(['green'])->toStartWith('green'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toStartWith() guards the subject type')
            ->toBe('toStartWith() requires a string subject. The subject type is array.');
    }

    #[Test]
    public function toEndWithPasses(): void
    {
        expect('greenlight')->because('toEndWith() passes')->toEndWith('light');
    }

    #[Test]
    public function toEndWithFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('greenlight')->toEndWith('green'),
        );

        expect($detail->message)->because('toEndWith() fails')->toBe("Expected 'greenlight' to end with 'green'.");
    }

    #[Test]
    public function notToEndWith(): void
    {
        expect('greenlight')->because('not() to end with')->not()->toEndWith('green');
    }

    #[Test]
    public function toEndWithGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(null)->toEndWith('x'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toEndWith() guards the subject type')
            ->toBe('toEndWith() requires a string subject. The subject type is null.');
    }

    #[Test]
    public function toHaveLengthPasses(): void
    {
        expect('abc')->because('toHaveLength() passes')->toHaveLength(3);
        expect('')->because('toHaveLength() passes')->toHaveLength(0);
        expect([1, 2])->because('toHaveLength() passes')->toHaveLength(2);
        expect(new \ArrayObject([1]))->because('toHaveLength() passes')->toHaveLength(1);
    }

    #[Test]
    public function toHaveLengthCountsCodePointsNotBytes(): void
    {
        expect('héllo')->because('toHaveLength() counts code points not bytes')->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthFallsBackToBytesForInvalidUtf8(): void
    {
        expect("\xC3\x28")->because('toHaveLength() counts bytes for invalid UTF-8')->toHaveLength(2);
    }

    #[Test]
    public function toHaveLengthFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect('abc')->toHaveLength(5),
        );

        expect($detail->message)->because('toHaveLength() fails')->toBe("Expected 'abc' (length 3) to have length 5.");
        expect($detail->expected)->because('toHaveLength() fails')->toBe('length 5');
    }

    #[Test]
    public function notToHaveLength(): void
    {
        expect('abc')->because('not() to have length')->not()->toHaveLength(5);
    }

    #[Test]
    public function toHaveLengthGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(42)->toHaveLength(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('toHaveLength() guards the subject type')
            ->toBe('toHaveLength() requires a string, array, or Countable subject. The subject type is int.');
    }
}
