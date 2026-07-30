<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final readonly class ToThrowZeroMessageTest
{
    #[Test]
    public function exactZeroMessageMatches(): void
    {
        Expect::that(static fn() => throw new \RuntimeException('0'))
            ->because('toThrow() MUST treat a zero message as an exact constraint')
            ->toThrow(\RuntimeException::class, message: '0');
    }

    #[Test]
    public function exactZeroMessageRejectsAndDescribesOtherMessages(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \RuntimeException('other'))
                ->toThrow(\RuntimeException::class, message: '0'),
        );

        Expect::that($detail->message)
            ->because('a zero exact-message mismatch MUST retain its constraint')
            ->toBe(
                "Expected a callable that threw RuntimeException with message 'other' "
                . "to throw RuntimeException with message '0'.",
            )
            ->and($detail->expected)
            ->toBe(\RuntimeException::class)
            ->and($detail->actual)
            ->toBe("a callable that threw RuntimeException with message 'other'");
    }
}
