<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\StorageConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Expect\Expect;

final readonly class StorageLayoutTest
{
    #[Test]
    public function defaultsPreserveTheCurrentSystemTemporaryPaths(): void
    {
        $workingDirectory = '/project';
        $key = \substr(\sha1($workingDirectory), 0, 12);
        $temporary = \rtrim(\sys_get_temp_dir(), '/');
        $layout = StorageLayout::resolve(new StorageConfiguration(), $workingDirectory);

        Expect::that($layout->runStateFile)
            ->because('default run state MUST retain its project-specific system temporary path')
            ->toBe($temporary . '/greenlight-state-' . $key . '.json');
        Expect::that($layout->cacheDirectory)
            ->because('the default data cache MUST use the system temporary directory')
            ->toBe($temporary);
        Expect::that($layout->generatedCodeDirectory)
            ->because('default generated code MUST retain its project-specific directory')
            ->toBe($temporary . '/greenlight-proxies-' . $key);
        Expect::that($layout->temporaryDirectory)
            ->because('default runtime data MUST use the system temporary directory')
            ->toBe($temporary);
    }

    #[Test]
    public function areaDirectoriesOverrideTheConfiguredRoot(): void
    {
        $layout = StorageLayout::resolve(new StorageConfiguration(
            rootDirectory: '.greenlight',
            stateDirectory: '/shared/state',
            temporaryDirectory: 'build/runtime',
        ), '/project');

        Expect::that($layout->runStateFile)
            ->because('an explicit state directory MUST not depend on the checkout path')
            ->toBe('/shared/state/run-state.json');
        Expect::that($layout->cacheDirectory)
            ->because('an area without an override MUST use its directory below the root')
            ->toBe('/project/.greenlight/cache');
        Expect::that($layout->generatedCodeDirectory)
            ->because('generated code without an override MUST use its directory below the root')
            ->toBe('/project/.greenlight/generated-code');
        Expect::that($layout->temporaryDirectory)
            ->because('relative area overrides MUST resolve against the initial working directory')
            ->toBe('/project/build/runtime');
    }

    #[Test]
    public function suiteIdentitySeparatesRunStateWithoutMovingOtherStorage(): void
    {
        $layout = StorageLayout::resolve(
            new StorageConfiguration(rootDirectory: '.greenlight'),
            '/project',
            'suites-123456789abc',
        );

        Expect::that($layout->runStateFile)
            ->because('suite selections MUST have separate failure and timing state')
            ->toBe('/project/.greenlight/state/run-state-suites-123456789abc.json');
        Expect::that($layout->cacheDirectory)
            ->because('suite selections MUST keep the configured discovery-cache directory')
            ->toBe('/project/.greenlight/cache');
    }
}
