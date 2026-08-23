<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/** Runs PHP test processes through the shared process support. */
final readonly class PhpSubprocess
{
    private function __construct() {}

    /**
     * @param non-empty-list<string> $arguments
     * @param array<string, string> $environment
     * @param list<string> $phpArguments
     *
     * @throws \RuntimeException when the process cannot start or its output cannot be read
     */
    public static function run(
        string $workingDirectory,
        array $arguments,
        array $environment = [],
        array $phpArguments = [],
    ): ProcessResult {
        return Subprocess::run(
            $workingDirectory,
            self::command($arguments, $phpArguments),
            $environment,
        );
    }

    /**
     * @param non-empty-list<string> $arguments
     * @param array<string, string> $environment
     * @param list<string> $phpArguments
     *
     * @throws \RuntimeException when the process cannot start
     */
    public static function start(
        string $workingDirectory,
        array $arguments,
        array $environment = [],
        array $phpArguments = [],
    ): Subprocess {
        return Subprocess::start(
            $workingDirectory,
            self::command($arguments, $phpArguments),
            $environment,
        );
    }

    /**
     * @template T of string
     *
     * @param non-empty-list<T> $arguments
     * @param list<T> $phpArguments
     *
     * @return non-empty-list<T|non-empty-string>
     */
    public static function command(array $arguments, array $phpArguments = []): array
    {
        return [
            \PHP_BINARY,
            ...$phpArguments,
            ...$arguments,
        ];
    }
}
