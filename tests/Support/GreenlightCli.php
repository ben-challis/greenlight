<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Fail;

/** Passes arguments directly to PHP and does not ask a shell to parse them. */
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

    public static function summaryLine(ProcessResult $result): string
    {
        $output = $result->output();

        if (\preg_match('/^\d+ tests?, \d+ passed(?:, \d+ failed)?(?:, \d+ errored)?(?:, \d+ skipped)?, \d+ expectations?$/m', $output, $matches) !== 1) {
            Fail::because("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}
