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
use Greenlight\Coverage\CoverageError;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Tests\Fixture\Coverage\RecordingFakeDriver;
use Greenlight\Tests\Support\FilesystemRestriction;

final class CoverageMissingIncludePathTest
{
    #[Test]
    public function anUnresolvedIncludePathRemainsRestrictive(): void
    {
        $configuration = new CoverageConfiguration(['future/src'], null, []);
        $settings = CoverageSettingsResolver::resolve($configuration, '/project');

        Expect::that($settings)
            ->because('The coverage configuration MUST create coverage settings.')
            ->toBeInstanceOf(CoverageSettings::class);

        Expect::that($settings->includePaths)
            ->because('an unresolved non-empty include path MUST remain absolute')
            ->toBe(['/project/future/src']);

        $collector = CoverageCollector::create(
            $settings,
            selector: new DriverSelector([RecordingFakeDriver::class]),
        );

        Expect::that($collector)
            ->because('The available driver MUST create a coverage collector.')
            ->toBeInstanceOf(CoverageCollector::class);

        $collector->start();

        Expect::that($collector->stop()->files())
            ->because('an unresolved include path MUST NOT broaden coverage to all files')
            ->toBe([]);
    }

    #[Test]
    public function branchCoverageRejectsPcovBeforeCollection(): void
    {
        $configuration = new CoverageConfiguration([], 'pcov', [], branchCoverage: true);

        Expect::that(static fn() => CoverageSettingsResolver::resolve($configuration, '/project'))
            ->because('a branch request MUST fail before discovery when pcov is selected')
            ->toThrow(
                CoverageError::class,
                message: 'Branch coverage requires the Xdebug coverage driver. Remove driver("pcov") or select driver("xdebug").',
            );
    }

    #[Test]
    #[Isolated]
    public function aRestrictedIncludePathFallsBackWithoutADiagnostic(): void
    {
        $root = \dirname(__DIR__, 4);
        $outside = \realpath(\dirname($root));

        Expect::that($outside)
            ->because('The test MUST resolve its restricted include path.')
            ->toBeString();

        FilesystemRestriction::toProject($root);

        $configuration = new CoverageConfiguration([$outside], null, []);
        $settings = ErrorTrap::run(
            static fn() => CoverageSettingsResolver::resolve($configuration, $root),
            $warning,
        );

        Expect::that($settings)
            ->because('The coverage configuration MUST create coverage settings.')
            ->toBeInstanceOf(CoverageSettings::class);

        Expect::that($settings->includePaths)
            ->because('a restricted include path MUST remain restrictive')
            ->toBe([$outside]);
        Expect::that($warning)
            ->because('a restricted include path MUST not leak an engine diagnostic')
            ->toBeNull();
    }
}
