<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Resolves CLI coverage configuration into runner settings.
 *
 * @internal
 */
final class CoverageSettingsResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function resolve(?CoverageConfiguration $configuration, string $workingDirectory): ?CoverageSettings
    {
        if (!$configuration instanceof CoverageConfiguration) {
            return null;
        }

        return new CoverageSettings(
            self::absolutePaths($configuration->includePaths, $workingDirectory),
            $configuration->driver,
            self::absolutePaths($configuration->excludePaths, $workingDirectory),
        );
    }

    /**
     * @param list<non-empty-string> $paths
     *
     * @return list<non-empty-string>
     */
    private static function absolutePaths(array $paths, string $workingDirectory): array
    {
        $absolutePaths = [];

        foreach ($paths as $path) {
            $absolute = \str_starts_with($path, '/')
                ? $path
                : \rtrim($workingDirectory, '/') . '/' . $path;
            $real = ErrorTrap::run(static fn() => \realpath($absolute));

            if ($real !== false) {
                $absolutePaths[] = $real;
            } elseif ($absolute !== '') {
                $absolutePaths[] = $absolute;
            }
        }

        return $absolutePaths;
    }
}
