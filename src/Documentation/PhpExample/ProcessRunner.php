<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Runs an analysis process and captures both output streams without a shell.
 *
 * @internal
 */
final readonly class ProcessRunner
{
    /**
     * @param non-empty-list<string> $command
     */
    public function run(string $root, array $command): ProcessResult
    {
        $process = \proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );

        if (!\is_resource($process)) {
            throw DocumentationExampleError::commandStartFailed(\implode(' ', $command));
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';

        while (true) {
            $read = [];

            if (!\feof($pipes[1])) {
                $read[] = $pipes[1];
            }

            if (!\feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read === []) {
                break;
            }

            $write = null;
            $except = null;
            $ready = \stream_select($read, $write, $except, 5);

            if ($ready === false) {
                throw DocumentationExampleError::toolOutputReadFailed();
            }

            foreach ($read as $stream) {
                $chunk = \stream_get_contents($stream);

                if ($chunk === false) {
                    throw DocumentationExampleError::toolOutputReadFailed();
                }

                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        return new ProcessResult($exitCode, $stdout, $stderr);
    }
}
