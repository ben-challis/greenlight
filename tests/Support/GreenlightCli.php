<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/**
 * Runs bin/greenlight synchronously with arguments passed directly to PHP.
 */
final class GreenlightCli
{
    private function __construct() {}

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @param list<string> $phpArguments arguments inserted after PHP_BINARY, such as -d settings
     *
     * @throws \RuntimeException when the process cannot be started or its output cannot be read
     */
    public static function run(
        string $workingDirectory,
        array $arguments,
        array $environment = [],
        array $phpArguments = [],
    ): ProcessResult {
        return self::start($workingDirectory, $arguments, $environment, $phpArguments)->complete();
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @param list<string> $phpArguments arguments inserted after PHP_BINARY, such as -d settings
     *
     * @throws \RuntimeException when the process cannot be started
     */
    public static function start(
        string $workingDirectory,
        array $arguments,
        array $environment = [],
        array $phpArguments = [],
    ): Subprocess {
        $root = \dirname(__DIR__, 2);

        return Subprocess::start(
            $workingDirectory,
            [
                \PHP_BINARY,
                ...$phpArguments,
                $root . '/bin/greenlight',
                ...$arguments,
            ],
            $environment,
        );
    }
}
