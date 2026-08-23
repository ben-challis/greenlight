<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/** Runs PHP test processes with the required isolated configuration. */
final readonly class PhpSubprocess
{
    /** @var array<string, string> */
    private const array ENVIRONMENT = [
        'DD_TRACE_ENABLED' => '0',
        'DD_TRACE_CLI_ENABLED' => '0',
        'DD_TRACE_STARTUP_LOGS' => '0',
        'DD_INSTRUMENTATION_TELEMETRY_ENABLED' => '0',
    ];

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
            [...$environment, ...self::ENVIRONMENT],
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
            [...$environment, ...self::ENVIRONMENT],
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
            '-d',
            'ddtrace.disable=1',
            ...$arguments,
        ];
    }
}
