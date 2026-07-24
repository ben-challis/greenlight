<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\ErrorTrap;

/**
 * Runs a command synchronously without crossing a shell.
 *
 * Arguments and environment overrides are passed to proc_open as structured
 * values. stdout and stderr are drained concurrently to prevent a full pipe
 * from deadlocking the child process.
 */
final class ProcessRunner
{
    private function __construct() {}

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string> $environment
     *
     * @throws \RuntimeException when the process cannot be started or its output cannot be read
     */
    public static function run(
        string $workingDirectory,
        array $command,
        array $environment = [],
    ): ProcessResult {
        $processEnvironment = null;

        if ($environment !== []) {
            $processEnvironment = \getenv();

            foreach ($environment as $name => $value) {
                $processEnvironment[$name] = $value;
            }
        }

        $process = \proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            $processEnvironment,
            ['bypass_shell' => true],
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start process.');
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        try {
            [$stdout, $stderr] = self::drain($pipes[1], $pipes[2]);
            $exitCode = \proc_close($process);
        } catch (\Throwable $failure) {
            foreach ([$pipes[1], $pipes[2]] as $pipe) {
                if (\is_resource($pipe)) {
                    ErrorTrap::run(static fn(): bool => \fclose($pipe));
                }
            }

            ErrorTrap::run(static fn(): bool => \proc_terminate($process, 9));
            ErrorTrap::run(static fn(): int => \proc_close($process));

            throw $failure;
        }

        return new ProcessResult(
            $exitCode,
            self::normalize($stdout),
            self::normalize($stderr),
        );
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return array{string, string}
     */
    private static function drain($stdout, $stderr): array
    {
        $streams = [1 => $stdout, 2 => $stderr];
        $captured = [1 => '', 2 => ''];

        while ($streams !== []) {
            $read = \array_values($streams);
            $write = null;
            $except = null;
            $ready = \stream_select($read, $write, $except, null);

            if ($ready === false) {
                throw new \RuntimeException('Could not read process output.');
            }

            foreach ($read as $stream) {
                $index = $stream === $stdout ? 1 : 2;
                $chunk = \stream_get_contents($stream);

                if (\is_string($chunk)) {
                    $captured[$index] .= $chunk;
                }

                if (\feof($stream)) {
                    \fclose($stream);
                    unset($streams[$index]);
                }
            }
        }

        return [$captured[1], $captured[2]];
    }

    private static function normalize(string $output): string
    {
        return \rtrim(\str_replace("\r\n", "\n", $output), "\n");
    }
}
