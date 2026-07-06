<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Raised when no config file exists where one was expected.
 *
 * @internal
 */
final class ConfigFileNotFound extends \RuntimeException
{
    public static function inDirectory(string $directory): self
    {
        return new self(\sprintf(
            'No %s found in %s. Create one that returns GreenlightConfig::create(), or point at one with --config=<path>.',
            ConfigLoader::FILE_NAME,
            $directory,
        ));
    }

    public static function atPath(string $path): self
    {
        return new self(\sprintf('Config file %s does not exist.', $path));
    }
}
