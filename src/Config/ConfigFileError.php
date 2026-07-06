<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Raised when the config file cannot be loaded: it does not exist, it threw
 * while being included, or it returned something other than a GreenlightConfig
 * builder.
 *
 * @internal
 */
final class ConfigFileError extends \RuntimeException
{
    public static function noneInDirectory(string $directory): self
    {
        return new self(\sprintf(
            'No %s found in "%s". Create one that returns GreenlightConfig::create(), or point at one with --config=<path>.',
            ConfigLoader::FILE_NAME,
            $directory,
        ));
    }

    public static function notFound(string $path): self
    {
        return new self(\sprintf('Config file "%s" does not exist.', $path));
    }

    public static function didNotReturnBuilder(string $file, mixed $returned): self
    {
        return new self(\sprintf(
            'Config file "%s" must return a %s instance, got %s. End the file with "return GreenlightConfig::create()->...;".',
            $file,
            GreenlightConfig::class,
            \get_debug_type($returned),
        ));
    }

    public static function threw(string $file, \Throwable $cause): self
    {
        return new self(\sprintf(
            'Config file "%s" threw %s: %s',
            $file,
            $cause::class,
            $cause->getMessage(),
        ), 0, $cause);
    }
}
