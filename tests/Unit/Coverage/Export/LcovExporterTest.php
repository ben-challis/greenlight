<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class LcovExporterTest
{
    #[Test]
    public function producesTheExactLcovTracefile(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/B.php', [2], []),
            new FileCoverage('/src/A.php', [3, 7], [5]),
        ]);

        $expected = <<<'LCOV'
            SF:/src/A.php
            DA:3,1
            DA:5,0
            DA:7,1
            LF:3
            LH:2
            end_of_record
            SF:/src/B.php
            DA:2,1
            LF:1
            LH:1
            end_of_record

            LCOV;

        Expect::that(new LcovExporter()->export($map))->because('produces the exact LCOV tracefile')
            ->toBe([LcovExporter::FILE_NAME => $expected]);
    }

    #[Test]
    public function emptyMapProducesAnEmptyTracefile(): void
    {
        Expect::that(new LcovExporter()->export(CoverageMap::empty()))->because('empty map produces an empty tracefile')
            ->toBe([LcovExporter::FILE_NAME => '']);
    }

    /**
     * @param non-empty-string $path
     */
    #[Test]
    #[DataSet('pathsThatCanInjectRecords')]
    public function lineBreaksInFilePathsAreRejected(string $path): void
    {
        $map = new CoverageMap([new FileCoverage($path, [1], [])]);

        Expect::that(static fn(): array => new LcovExporter()->export($map))
            ->because('LCOV SF records MUST stay on one line')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'LCOV file paths cannot contain line breaks.',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function pathsThatCanInjectRecords(): iterable
    {
        yield 'line feed' => ["/src/A.php\nDA:999,1"];
        yield 'carriage return' => ["/src/A.php\rDA:999,1"];
    }
}
