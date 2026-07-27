<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Reports a problem with greenlight.php. The file can be absent, throw an
 * exception, or return a value other than GreenlightConfig.
 *
 * @internal
 */
final class ConfigFileError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function noneInDirectory(string $directory): self
    {
        return new self(\sprintf(
            'No %s found in "%s". Create one that returns GreenlightConfig::create(). Alternatively, use --config=<path> to select a configuration file.',
            ConfigLoader::FILE_NAME,
            $directory,
        ));
    }

    public static function notFound(string $path): self
    {
        return new self(\sprintf('Configuration file "%s" does not exist.', $path));
    }

    public static function didNotReturnBuilder(string $file, mixed $returned): self
    {
        return new self(\sprintf(
            'Configuration file "%s" returned %s. It must return a %s instance. End the file with "return GreenlightConfig::create()->...;".',
            $file,
            \get_debug_type($returned),
            GreenlightConfig::class,
        ));
    }

    public static function threw(string $file, \Throwable $cause): self
    {
        return new self(\sprintf(
            'Configuration file "%s" threw %s: %s',
            $file,
            $cause::class,
            $cause->getMessage(),
        ), $cause);
    }
}
