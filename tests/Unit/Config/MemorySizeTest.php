<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\MemorySize;
use Greenlight\Tests\Support\Check;

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
            Check::same($expectedBytes, MemorySize::parseToBytes((string) $input), \sprintf("bytes for '%s'", $input));
        }
    }

    #[Test]
    public function rejectsGarbage(): void
    {
        $garbage = ['', 'abc', '-5M', '1T', '0', 'M', '1.5G', '10 apples'];

        foreach ($garbage as $input) {
            Check::throws(
                static function () use ($input): void {
                    MemorySize::parseToBytes($input);
                },
                InvalidConfiguration::class,
                \sprintf("parsing '%s'", $input),
            );
        }
    }

    #[Test]
    public function formatsBytesBackToShortestExactForm(): void
    {
        Check::same('256M', MemorySize::format(268435456), 'formatted 256M');
        Check::same('1G', MemorySize::format(1073741824), 'formatted 1G');
        Check::same('512K', MemorySize::format(524288), 'formatted 512K');
        Check::same('1000B', MemorySize::format(1000), 'formatted plain bytes');
    }
}
