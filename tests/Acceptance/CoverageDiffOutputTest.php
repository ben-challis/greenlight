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

final readonly class CoverageDiffOutputTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function removedUncoveredFilesDoNotProduceNeutralFileDeltas(): void
    {
        $directory = $this->tempDirectory->subdirectory('neutral-file-delta');
        $kept = new FileCoverage('/project/src/Kept.php', [1], []);
        CoverageJson::write(
            $directory . '/baseline.json',
            new CoverageMap([
                $kept,
                new FileCoverage('/project/src/Removed.php', [], [1]),
            ]),
        );
        CoverageJson::write(
            $directory . '/current.json',
            new CoverageMap([$kept]),
        );

        $result = GreenlightCli::run(
            $directory,
            [
                'coverage:diff',
                '--baseline=baseline.json',
                '--current=current.json',
            ],
        );

        Expect::that($result->exitCode)
            ->because('removing an uncovered file MUST NOT fail the coverage diff')
            ->toBe(0);
        Expect::that($result->output())
            ->because('a removed zero-percent file has no useful file-level delta')
            ->toBe('Coverage: baseline 50.00%, current 100.00% (+50.00)');
    }
}
