<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Internal\Php\ErrorTrap;

/** @internal */
final class ConfigLoader
{
    public const string FILE_NAME = 'greenlight.php';

    /**
     * @throws ConfigFileError
     */
    public function loadFromDirectory(string $directory): GreenlightConfig
    {
        $file = \rtrim($directory, '/') . '/' . self::FILE_NAME;
        $exists = ErrorTrap::run(static fn() => \is_file($file));

        if (!$exists) {
            throw ConfigFileError::noneInDirectory($directory);
        }

        return $this->loadFile($file);
    }

    /**
     * @throws ConfigFileError
     */
    public function loadFile(string $file): GreenlightConfig
    {
        $exists = ErrorTrap::run(static fn() => \is_file($file));

        if (!$exists) {
            throw ConfigFileError::notFound($file);
        }

        $returned = ErrorTrap::run(
            static fn() => require $file,
            wrap: static fn(\Throwable $cause): ConfigFileError => ConfigFileError::threw($file, $cause),
        );

        if (!$returned instanceof GreenlightConfig) {
            throw ConfigFileError::didNotReturnBuilder($file, $returned);
        }

        return $returned;
    }
}
