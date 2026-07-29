<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageDiffZeroPercentageOutputTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aPresentZeroPercentageFileDoesNotRenderAsAbsent(): void
    {
        $directory = $this->tempDirectory->subdirectory('zero-percentage-file-delta');
        $baseline = new CoverageMap([
            new FileCoverage('/project/src/Zero.php', [1], []),
        ]);
        $current = new CoverageMap([
            new FileCoverage('/project/src/Zero.php', [], [1]),
        ]);
        $this->writeExport($directory . '/baseline.json', $baseline);
        $this->writeExport($directory . '/current.json', $current);

        $result = GreenlightCli::run(
            $directory,
            [
                'coverage:diff',
                '--baseline=baseline.json',
                '--current=current.json',
            ],
        );

        Expect::that($result->exitCode)
            ->because('a zero-percent current file MUST report a coverage regression')
            ->toBe(1)
            ->and($result->stdoutLines())
            ->because('a present zero-percent file MUST NOT render as absent')
            ->toBe([
                'Coverage: baseline 100.00%, current 0.00% (-100.00)',
                '/project/src/Zero.php: 100.00% -> 0.00% (-100.00), newly uncovered lines: 1',
            ]);
    }

    private function writeExport(string $path, CoverageMap $map): void
    {
        $files = new JsonExporter()->export($map);
        \file_put_contents($path, $files[JsonExporter::FILE_NAME]);
    }
}
