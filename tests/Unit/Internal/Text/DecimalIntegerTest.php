<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Text;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Text\DecimalInteger;

final class DecimalIntegerTest
{
    #[Test]
    #[DataSet('decimalText')]
    public function parsesOnlyRepresentableNonnegativeDecimalText(string $raw, ?int $expected): void
    {
        Expect::that(DecimalInteger::parse($raw))
            ->because('decimal integer parsing MUST accept every representable value without overflow')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function decimalText(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'zero padded' => ['0000', 0];
        yield 'machine maximum' => [(string) \PHP_INT_MAX, \PHP_INT_MAX];
        yield 'padded machine maximum' => ['000' . \PHP_INT_MAX, \PHP_INT_MAX];
        yield 'same-width overflow' => [
            \substr((string) \PHP_INT_MAX, 0, -1) . '8',
            null,
        ];
        yield 'overflow' => [\PHP_INT_MAX . '0', null];
        yield 'negative' => ['-1', null];
        yield 'fraction' => ['1.0', null];
        yield 'trailing newline' => ["1\n", null];
        yield 'empty' => ['', null];
    }
}
