<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Raised when a config file exists but cannot be used: it threw while being
 * included, or returned something other than a GreenlightConfig builder.
 *
 * @internal
 */
final class InvalidConfigFile extends \RuntimeException
{
    public static function didNotReturnBuilder(string $file, mixed $returned): self
    {
        return new self(\sprintf(
            'Config file %s must return a %s instance, got %s. End the file with "return GreenlightConfig::create()->...;".',
            $file,
            GreenlightConfig::class,
            \get_debug_type($returned),
        ));
    }

    public static function threw(string $file, \Throwable $cause): self
    {
        return new self(\sprintf(
            'Config file %s threw %s: %s',
            $file,
            $cause::class,
            $cause->getMessage(),
        ), 0, $cause);
    }
}
