<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageSettingsResolver;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\Collection\CoverageCollector;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\Collection\Driver\DriverSelector;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Tests\Fixture\Coverage\RecordingFakeDriver;
use Greenlight\Tests\Support\FilesystemRestriction;

use function Greenlight\expect;

final class CoverageMissingIncludePathTest
{
    #[Test]
    public function anUnresolvedIncludePathRemainsRestrictive(): void
    {
        $configuration = new CoverageConfiguration(['future/src'], null, []);
        $settings = CoverageSettingsResolver::resolve($configuration, '/project');

        expect($settings)
            ->because('The coverage configuration MUST create coverage settings.')
            ->toBeInstanceOf(CoverageSettings::class);

        expect($settings->includePaths)
            ->because('an unresolved non-empty include path MUST remain absolute')
            ->toBe(['/project/future/src']);

        $collector = CoverageCollector::create(
            $settings,
            selector: new DriverSelector([RecordingFakeDriver::class]),
        );

        expect($collector)
            ->because('The available driver MUST create a coverage collector.')
            ->toBeInstanceOf(CoverageCollector::class);

        $collector->start();

        expect($collector->stop()->files())
            ->because('an unresolved include path MUST NOT broaden coverage to all files')
            ->toBe([]);
    }

    #[Test]
    #[Isolated]
    public function aRestrictedIncludePathFallsBackWithoutADiagnostic(): void
    {
        $root = \dirname(__DIR__, 4);
        $outside = \realpath(\dirname($root));

        expect($outside)
            ->because('The test MUST resolve its restricted include path.')
            ->toBeString();

        FilesystemRestriction::toProject($root);

        $configuration = new CoverageConfiguration([$outside], null, []);
        $settings = ErrorTrap::run(
            static fn() => CoverageSettingsResolver::resolve($configuration, $root),
            $warning,
        );

        expect($settings)
            ->because('The coverage configuration MUST create coverage settings.')
            ->toBeInstanceOf(CoverageSettings::class);

        expect($settings->includePaths)
            ->because('a restricted include path MUST remain restrictive')
            ->toBe([$outside]);
        expect($warning)
            ->because('a restricted include path MUST not leak an engine diagnostic')
            ->toBeNull();
    }
}
