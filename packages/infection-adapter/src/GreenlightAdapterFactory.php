<?php

declare(strict_types=1);

namespace Greenlight\InfectionAdapter;

use Infection\AbstractTestFramework\TestFrameworkAdapter;
use Infection\AbstractTestFramework\TestFrameworkAdapterFactory;

final class GreenlightAdapterFactory implements TestFrameworkAdapterFactory
{
    /** @param array<mixed> $sourceDirectories */
    public static function create(
        string $testFrameworkExecutable,
        string $tmpDir,
        string $testFrameworkConfigPath,
        ?string $testFrameworkConfigDir,
        string $jUnitFilePath,
        string $projectDir,
        array $sourceDirectories,
        bool $skipCoverage,
    ): TestFrameworkAdapter {
        $sources = [];

        foreach ($sourceDirectories as $sourceDirectory) {
            if (!\is_string($sourceDirectory) || $sourceDirectory === '') {
                throw new \InvalidArgumentException('Infection source directories must be non-empty strings.');
            }

            $sources[] = $sourceDirectory;
        }

        return new GreenlightAdapter(
            $testFrameworkExecutable,
            $tmpDir,
            $testFrameworkConfigPath,
            $projectDir,
            $sources,
        );
    }

    public static function getAdapterName(): string
    {
        return 'greenlight';
    }

    public static function getExecutableName(): string
    {
        return 'greenlight-infection';
    }
}
