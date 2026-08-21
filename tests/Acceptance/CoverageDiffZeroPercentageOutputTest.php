<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageDiffZeroPercentageOutputTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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
        CoverageJson::write($directory . '/baseline.json', $baseline);
        CoverageJson::write($directory . '/current.json', $current);

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
            ->toBe(1);
        Expect::that($result->stdoutLines())
            ->because('a present zero-percent file MUST NOT render as absent')
            ->toBe([
                'Coverage: baseline 100.00%, current 0.00% (-100.00)',
                '/project/src/Zero.php: 100.00% -> 0.00% (-100.00), newly uncovered lines: 1',
            ]);
    }
}
