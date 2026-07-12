<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Locates and loads greenlight.php, returning the builder it produced.
 *
 * The caller decides when to build() and what overrides to apply afterwards.
 *
 * @internal
 */
final class ConfigLoader
{
    public const string FILE_NAME = 'greenlight.php';

    /**
     * @throws ConfigFileError
     */
    public function loadFromDirectory(string $directory): GreenlightConfig
    {
        $file = \rtrim($directory, '/') . '/' . self::FILE_NAME;

        if (!\is_file($file)) {
            throw ConfigFileError::noneInDirectory($directory);
        }

        return $this->loadFile($file);
    }

    /**
     * @throws ConfigFileError
     */
    public function loadFile(string $file): GreenlightConfig
    {
        if (!\is_file($file)) {
            throw ConfigFileError::notFound($file);
        }

        try {
            $returned = (static fn(): mixed => require $file)();
        } catch (\Throwable $cause) {
            throw ConfigFileError::threw($file, $cause);
        }

        if (!$returned instanceof GreenlightConfig) {
            throw ConfigFileError::didNotReturnBuilder($file, $returned);
        }

        return $returned;
    }
}
