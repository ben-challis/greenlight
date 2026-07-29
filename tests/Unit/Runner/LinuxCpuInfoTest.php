<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\LinuxCpuInfo;

final class LinuxCpuInfoTest
{
    #[Test]
    #[DataSet('cpuInfoDocuments')]
    public function processorCountUsesOnlyProcessorRecords(string $cpuinfo, ?int $expected): void
    {
        $count = LinuxCpuInfo::processorCount($cpuinfo);

        Expect::that($count)
            ->because('the Linux probe MUST count only processor records')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string, positive-int|null}>
     */
    public static function cpuInfoDocuments(): iterable
    {
        yield 'two processors' => [
            <<<'CPUINFO'
            processor   : 0
            vendor_id   : GenuineIntel

            processor   : 1
            vendor_id   : GenuineIntel
            CPUINFO,
            2,
        ];
        yield 'one processor with a tab separator' => ["processor\t: 0\n", 1];
        yield 'empty document' => ['', null];
        yield 'misleading and indented fields' => [
            <<<'CPUINFO'
            physical processor : 0
            processor count    : 8
             processor         : 1
            CPUINFO,
            null,
        ];
    }
}
