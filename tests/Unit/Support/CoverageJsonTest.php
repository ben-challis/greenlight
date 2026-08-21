<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;

final readonly class CoverageJsonTest
{
    public function __construct(private TemporaryDirectory $workspace) {}

    #[Test]
    public function writeAndReadPreserveTheCoverageMap(): void
    {
        $path = $this->workspace->path() . '/coverage.json';
        $map = new CoverageMap([
            new FileCoverage('/project/src/Probe.php', [1], [2]),
        ]);

        CoverageJson::write($path, $map);

        Expect::that(CoverageJson::read($path)->toWire())
            ->because('the shared coverage JSON fixture MUST preserve the coverage map')
            ->toBe($map->toWire());
    }

    #[Test]
    public function readFailsExplicitlyWhenTheSourceIsUnavailable(): void
    {
        $path = $this->workspace->path() . '/missing/coverage.json';

        Expect::that(static fn(): CoverageMap => CoverageJson::read($path))
            ->because('a coverage JSON read failure MUST identify its source')
            ->toThrow(
                ExpectationFailed::class,
                matching: '/Expected to read coverage JSON document "'
                    . \preg_quote($path, '/')
                    . '"/',
            );
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
