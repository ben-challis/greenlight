<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\CoverageJson;

final readonly class CoverageJsonTest
{
    public function __construct(private TempDirectory $workspace) {}

    #[Test]
    public function writeCreatesAnImportableCoverageFixture(): void
    {
        $path = $this->workspace->path() . '/coverage.json';
        $map = new CoverageMap([
            new FileCoverage('/project/src/Probe.php', [1], [2]),
        ]);

        CoverageJson::write($path, $map);

        Expect::that(JsonExporter::import((string) \file_get_contents($path))->toWire())
            ->because('the shared fixture writer MUST preserve the coverage map')
            ->toBe($map->toWire());
    }

    #[Test]
    public function writeFailsExplicitlyWhenTheTargetIsUnavailable(): void
    {
        $path = $this->workspace->path() . '/missing/coverage.json';

        Expect::that(static function () use ($path): void {
            CoverageJson::write($path, CoverageMap::empty());
        })
            ->because('a coverage fixture write failure MUST identify its target')
            ->toThrow(
                ExpectationFailed::class,
                matching: '/Expected to write coverage JSON fixture "'
                    . \preg_quote($path, '/')
                    . '"/',
            );
    }
}
