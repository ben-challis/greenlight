<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\ErrorTrap;

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

        if (!ErrorTrap::run(static fn(): bool => \is_file($file))) {
            throw ConfigFileError::noneInDirectory($directory);
        }

        return $this->loadFile($file);
    }

    /**
     * @throws ConfigFileError
     */
    public function loadFile(string $file): GreenlightConfig
    {
        if (!ErrorTrap::run(static fn(): bool => \is_file($file))) {
            throw ConfigFileError::notFound($file);
        }

        try {
            $returned = ErrorTrap::run(static fn(): mixed => require $file);
        } catch (\Throwable $cause) {
            throw ConfigFileError::threw($file, $cause);
        }

        if (!$returned instanceof GreenlightConfig) {
            throw ConfigFileError::didNotReturnBuilder($file, $returned);
        }

        return $returned;
    }
}
