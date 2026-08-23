<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\CoverageError;
use Greenlight\Internal\Php\ErrorTrap;

/** Resolves CLI coverage configuration into runner settings.
 *
 * @internal
 */
final class CoverageSettingsResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @throws CoverageError */
    public static function resolve(?CoverageConfiguration $configuration, string $workingDirectory): ?CoverageSettings
    {
        if (!$configuration instanceof CoverageConfiguration) {
            return null;
        }

        $include = [];

        foreach ($configuration->includePaths as $path) {
            $absolute = \str_starts_with($path, '/')
                ? $path
                : \rtrim($workingDirectory, '/') . '/' . $path;
            $real = ErrorTrap::run(static fn() => \realpath($absolute));

            if ($real !== false) {
                $include[] = $real;
            } elseif ($absolute !== '') {
                $include[] = $absolute;
            }
        }

        if ($configuration->perTestTarget !== null && $include === []) {
            throw CoverageError::perTestIncludeRequired();
        }

        return new CoverageSettings($include, $configuration->driver, $configuration->perTestTarget !== null);
    }
}
