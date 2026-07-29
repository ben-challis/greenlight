<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Config\CoverageConfiguration;
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
        $application = new \ReflectionClass(Application::class)->newInstanceWithoutConstructor();
        $configuration = new CoverageConfiguration(['future/src'], null, []);
        $settings = new \ReflectionMethod(Application::class, 'coverageSettings')
            ->invoke($application, $configuration, '/project');

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
}
