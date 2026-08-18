<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\CoverageConfiguration;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\FilePath;
use Greenlight\Runner\CoverageSettings;

/** Resolves CLI coverage configuration into runner settings.
 *
 * @internal
 */
final class CoverageSettingsResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function resolve(
        ?CoverageConfiguration $configuration,
        string $workingDirectory,
        string $directorySeparator = \DIRECTORY_SEPARATOR,
    ): ?CoverageSettings {
        if (!$configuration instanceof CoverageConfiguration) {
            return null;
        }

        $include = [];

        foreach ($configuration->includePaths as $path) {
            $absolute = FilePath::resolve($path, $workingDirectory, $directorySeparator);
            $real = ErrorTrap::run(static fn() => \realpath($absolute));

            if ($real !== false) {
                $include[] = $real;
            } elseif ($absolute !== '') {
                $include[] = $absolute;
            }
        }

        return new CoverageSettings($include, $configuration->driver);
    }
}
