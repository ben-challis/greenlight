<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Loads and resolves one CLI configuration and its filesystem paths.
 *
 * @internal
 */
final readonly class ConfigurationLoader
{
    /**
     * @throws CliError
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     */
    public function load(ParsedArguments $arguments, string $workingDirectory): LoadedConfiguration
    {
        $overrides = CliOverrides::fromArguments($arguments);
        $loader = new ConfigLoader();
        $configArgument = $arguments->value('config');

        if ($configArgument !== null) {
            $configFile = self::absolutePath($configArgument, $workingDirectory);
            $builder = $loader->loadFile($configFile);
        } else {
            $configFile = \rtrim($workingDirectory, '/') . '/' . ConfigLoader::FILE_NAME;
            $builder = $loader->loadFromDirectory($workingDirectory);
        }

        $selection = $overrides->selection;
        $fileIds = $this->testIdsFromFiles($arguments, $workingDirectory);

        if ($fileIds !== []) {
            $selection = $selection->withExactIds(\array_values(\array_unique([
                ...$selection->include->exactIds,
                ...$fileIds,
            ])));
        }

        if ($selection->exclude->paths !== []) {
            $selection = $selection->withExcludedPaths($this->resolvedPathPrefixes($selection->exclude->paths, $workingDirectory));
        }

        $resolved = ConfigurationResolver::resolve($builder->build(), new CliOverrides(
            execution: $overrides->execution,
            selection: $selection,
            suiteNames: $overrides->suiteNames,
            suiteTags: $overrides->suiteTags,
            seed: $overrides->seed,
            repeat: $overrides->repeat,
            coverage: $overrides->coverage,
        ));

        return new LoadedConfiguration($resolved, $configFile, $overrides, self::directories($resolved, $workingDirectory));
    }

    /**
     * @param list<non-empty-string> $prefixes
     * @return list<non-empty-string>
     */
    private function resolvedPathPrefixes(array $prefixes, string $workingDirectory): array
    {
        $resolved = [];

        foreach ($prefixes as $prefix) {
            $absolute = self::absolutePath($prefix, $workingDirectory);
            $real = ErrorTrap::run(static fn() => \realpath($absolute));
            if ($real !== false) {
                $resolved[] = $real;
            } elseif ($absolute !== '') {
                $resolved[] = $absolute;
            }
        }

        return $resolved;
    }

    /** @return list<non-empty-string> */
    public static function directories(ResolvedConfiguration $configuration, string $workingDirectory): array
    {
        $paths = $configuration->suiteSelection->paths($configuration->discovery);
        $directories = [];
        foreach ($paths as $path) {
            $absolute = self::absolutePath($path, $workingDirectory);
            if ($absolute !== '' && !\in_array($absolute, $directories, true)) {
                $directories[] = $absolute;
            }
        }
        return $directories;
    }

    public static function absolutePath(string $path, string $workingDirectory): string
    {
        return \str_starts_with($path, '/') ? $path : \rtrim($workingDirectory, '/') . '/' . $path;
    }

    /**
     * @return list<non-empty-string>
     * @throws CliError
     */
    private function testIdsFromFiles(ParsedArguments $arguments, string $workingDirectory): array
    {
        $ids = [];

        foreach ($arguments->values('test-id-file') as $file) {
            if ($file === '') {
                throw CliError::optionRequiresValue('test-id-file');
            }

            $path = self::absolutePath($file, $workingDirectory);
            $lines = ErrorTrap::run(static fn() => \file($path, \FILE_IGNORE_NEW_LINES), $warning);

            if (!\is_array($lines)) {
                throw CliError::exactTestFileUnreadable($path, $warning);
            }

            $fileIds = [];

            foreach ($lines as $line) {
                $id = \trim($line);

                if ($id !== '') {
                    $fileIds[$id] = true;
                }
            }

            if ($fileIds === []) {
                throw CliError::exactTestFileEmpty($path);
            }

            $ids += $fileIds;
        }

        return \array_keys($ids);
    }

}
