<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\CoverageConfiguration;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Runner\CoverageSettings;

/** Resolves CLI coverage configuration into runner settings.
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

        return new CoverageSettings($include, $configuration->driver);
    }
}
