<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

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
    public function formatsBytesBackToShortestExactForm(): void
    {
        Expect::that(MemorySize::format(268435456))->toBe('256M');
        Expect::that(MemorySize::format(1073741824))->toBe('1G');
        Expect::that(MemorySize::format(524288))->toBe('512K');
        Expect::that(MemorySize::format(1000))->toBe('1000B');
    }
}
