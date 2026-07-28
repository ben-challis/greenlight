<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\MemorySize;
use Greenlight\Expect\Expect;

final class MemorySizeTest
{
    #[Test]
    public function parsesSuffixedAndPlainSizes(): void
    {
        $cases = [
            '512K' => 524288,
            '256M' => 268435456,
            '1G' => 1073741824,
            '4096' => 4096,
            '1' => 1,
            '128m' => 134217728,
            '256MB' => 268435456,
            ' 64M ' => 67108864,
        ];

        foreach ($cases as $input => $expectedBytes) {
            Expect::that(MemorySize::parseToBytes((string) $input))->toBe($expectedBytes);
        }
    }

    #[Test]
    public function rejectsGarbage(): void
    {
        $garbage = ['', 'abc', '-5M', '1T', '0', 'M', '1.5G', '10 apples'];

        foreach ($garbage as $input) {
            Expect::that(static function () use ($input): void {
                MemorySize::parseToBytes($input);
            })->toThrow(InvalidConfiguration::class);
        }
    }

    #[Test]
    #[DataSet('overflowingSizes')]
    public function rejectsSizesThatOverflowTheIntegerByteCount(string $input): void
    {
        Expect::that(static function () use ($input): void {
            MemorySize::parseToBytes($input);
        })
            ->because('the parsed byte count MUST fit in a platform integer')
            ->toThrow(
                InvalidConfiguration::class,
                message: \sprintf(
                    'Invalid memory size "%s". The value does not fit in an integer byte count.',
                    $input,
                ),
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function overflowingSizes(): iterable
    {
        yield 'plain bytes exceed the platform integer range' => [
            \PHP_INT_MAX . '0',
        ];

        yield 'suffix multiplication exceeds the platform integer range' => [
            \intdiv(\PHP_INT_MAX, 1024) + 1 . 'K',
        ];
    }

    #[Test]
    public function formatsBytesBackToShortestExactForm(): void
    {
        Expect::that(MemorySize::format(268435456))->because('formats bytes back to shortest exact form')->toBe('256M');
        Expect::that(MemorySize::format(1073741824))->because('formats bytes back to shortest exact form')->toBe('1G');
        Expect::that(MemorySize::format(524288))->because('formats bytes back to shortest exact form')->toBe('512K');
        Expect::that(MemorySize::format(1000))->because('formats bytes back to shortest exact form')->toBe('1000B');
    }
}
