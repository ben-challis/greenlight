<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Locates and loads greenlight.php, returning the builder it produced. The
 * caller decides when to build() and what overrides to apply afterwards.
 *
 * @internal
 */
final class ConfigLoader
{
    public const string FILE_NAME = 'greenlight.php';

    public function loadFromDirectory(string $directory): GreenlightConfig
    {
        $file = \rtrim($directory, '/') . '/' . self::FILE_NAME;

        if (!\is_file($file)) {
            throw ConfigFileNotFound::inDirectory($directory);
        }

        return $this->loadFile($file);
    }

    public function loadFile(string $file): GreenlightConfig
    {
        if (!\is_file($file)) {
            throw ConfigFileNotFound::atPath($file);
        }

        try {
            $returned = (static fn(): mixed => require $file)();
        } catch (\Throwable $cause) {
            throw InvalidConfigFile::threw($file, $cause);
        }

        if (!$returned instanceof GreenlightConfig) {
            throw InvalidConfigFile::didNotReturnBuilder($file, $returned);
        }

        return $returned;
    }
}
