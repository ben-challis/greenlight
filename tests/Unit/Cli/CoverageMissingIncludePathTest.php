<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\CoverageSettingsResolver;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Core\ErrorTrap;
use Greenlight\Coverage\Driver\DriverSelector;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Tests\Fixture\Coverage\RecordingFakeDriver;

final class CoverageMissingIncludePathTest
{
    #[Test]
    public function anUnresolvedIncludePathRemainsRestrictive(): void
    {
        $configuration = new CoverageConfiguration(['future/src'], null, []);
        $settings = CoverageSettingsResolver::resolve($configuration, '/project');

        if (!$settings instanceof CoverageSettings) {
            Fail::because('Expected coverage configuration to create coverage settings.');
        }

        Expect::that($settings->includePaths)
            ->because('an unresolved non-empty include path MUST remain absolute')
            ->toBe(['/project/future/src']);

        $collector = CoverageCollector::create(
            $settings,
            selector: new DriverSelector([RecordingFakeDriver::class]),
        );

        if (!$collector instanceof CoverageCollector) {
            Fail::because('Expected the available driver to create a coverage collector.');
        }

        $collector->start();

        Expect::that($collector->stop()->files())
            ->because('an unresolved include path MUST NOT broaden coverage to all files')
            ->toBe([]);
    }

    #[Test]
    #[Isolated]
    public function aRestrictedIncludePathFallsBackWithoutADiagnostic(): void
    {
        $root = \dirname(__DIR__, 3);
        $outside = \realpath(\dirname($root));

        if (!\is_string($outside)) {
            Fail::because('The test could not resolve its restricted include path.');
        }

        $previousOpenBasedir = \ini_set(
            'open_basedir',
            $root . \PATH_SEPARATOR . \sys_get_temp_dir(),
        );

        if ($previousOpenBasedir === false) {
            Fail::because('The test could not restrict file system access.');
        }

        $configuration = new CoverageConfiguration([$outside], null, []);
        $settings = ErrorTrap::run(
            static fn(): ?CoverageSettings => CoverageSettingsResolver::resolve($configuration, $root),
            $warning,
        );

        if (!$settings instanceof CoverageSettings) {
            Fail::because('Expected coverage configuration to create coverage settings.');
        }

        Expect::that($settings->includePaths)
            ->because('a restricted include path MUST remain restrictive')
            ->toBe([$outside]);
        Expect::that($warning)
            ->because('a restricted include path MUST not leak an engine diagnostic')
            ->toBeNull();
    }
}
