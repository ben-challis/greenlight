<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class PatternRuntimeErrorTest
{
    private const string MALFORMED_UTF8 = "\xC3\x28";

    #[Test]
    public function negatedToMatchFailsOnPcreRuntimeError(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(self::MALFORMED_UTF8)->not()->toMatch('/x/u'),
        );

        expect($detail->message)
            ->because('a PCRE run-time error MUST be a usage failure that negation cannot reverse')
            ->toBe('toMatch() could not apply the pattern /x/u: Malformed UTF-8 characters, possibly incorrectly encoded.');
    }

    #[Test]
    public function toMatchFailsOnPcreRuntimeError(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(self::MALFORMED_UTF8)->toMatch('/x/u'),
        );

        expect($detail->message)
            ->because('a PCRE run-time error MUST name the PCRE error')
            ->toContain('Malformed UTF-8');
    }

    #[Test]
    public function negatedToThrowMatchingFailsOnPcreRuntimeError(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect()
                ->calling(static fn() => throw new \RuntimeException(self::MALFORMED_UTF8))
                ->not()
                ->toThrow(\RuntimeException::class, matching: '/x/u'),
        );

        expect($detail->message)
            ->because('a PCRE run-time error in toThrow(matching:) MUST be a usage failure that negation cannot reverse')
            ->toBe('toThrow() could not apply the pattern /x/u: Malformed UTF-8 characters, possibly incorrectly encoded.');
    }
}
