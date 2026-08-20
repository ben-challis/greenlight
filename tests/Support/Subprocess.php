<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\ErrorTrap;

/**
 * The caller of start() owns the active process handle. After start() returns,
 * the caller MUST immediately guarantee that terminate() will run. After
 * wait() collects the result, terminate() has no effect.
 */
final class Subprocess
{
    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct(
        private $process,
        private array $pipes,
    ) {}

    private string $stdout = '';

    private string $stderr = '';

    private bool $finished = false;

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
        return self::start($workingDirectory, $command, $environment)->complete();
    }

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string> $environment
     *
     * @throws \RuntimeException when the process cannot be started
     */
    public static function start(
        string $workingDirectory,
        array $command,
        array $environment = [],
    ): self {
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

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        return new self($process, $pipes);
    }

    /**
     * @throws \RuntimeException when stdin cannot accept the input
     */
    public function write(string $input): void
    {
        $stdin = $this->pipes[0] ?? null;

        if (!\is_resource($stdin)) {
            throw new \RuntimeException('Could not write to process stdin.');
        }

        $offset = 0;
        $length = \strlen($input);

        while ($offset < $length) {
            $written = ErrorTrap::run(static fn() => \fwrite($stdin, \substr($input, $offset)));

            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not write to process stdin.');
            }

            $offset += $written;
        }

        if (!ErrorTrap::run(static fn() => \fflush($stdin))) {
            throw new \RuntimeException('Could not write to process stdin.');
        }
    }

    private function closeInput(): void
    {
        $this->closePipe(0);
    }

    /**
     * @throws \RuntimeException when output cannot be read
     */
    public function pump(): void
    {
        $read = $this->outputPipes();

        if ($read === []) {
            return;
        }

        $write = null;
        $except = null;
        $ready = \stream_select($read, $write, $except, 0, 0);

        if ($ready === false) {
            throw new \RuntimeException('Could not read process output.');
        }

        $this->capture($read);
    }

    /**
     * @throws \InvalidArgumentException when the timeout is not finite
     * @throws \RuntimeException when the expected output is not seen before the deadline
     */
    public function readStdoutUntil(string $needle, float $timeoutSeconds): string
    {
        $deadline = $this->deadline($timeoutSeconds);
        $offset = \strlen($this->stdout);

        if ($this->finished) {
            throw new \RuntimeException(\sprintf(
                "Process exited before stdout contained '%s'. Stdout:\n%s\nStderr:\n%s",
                $needle,
                $this->stdout,
                $this->stderr,
            ));
        }

        while ($this->monotonicTime() < $deadline) {
            $this->pump();
            $output = \substr($this->stdout, $offset);

            if (\str_contains($output, $needle)) {
                return $output;
            }

            if ($this->outputPipes() === [] && !\proc_get_status($this->process)['running']) {
                throw new \RuntimeException(\sprintf(
                    "Process exited before stdout contained '%s'. Stdout:\n%s\nStderr:\n%s",
                    $needle,
                    $this->stdout,
                    $this->stderr,
                ));
            }

            \usleep(50_000);
        }

        throw new \RuntimeException(\sprintf(
            "Timed out waiting for '%s'. Output so far:\n%s",
            $needle,
            \substr($this->stdout, $offset),
        ));
    }

    /**
     * @throws \RuntimeException when the signal cannot be sent
     */
    public function signal(int $signal): void
    {
        if (!\proc_terminate($this->process, $signal)) {
            throw new \RuntimeException(\sprintf('Could not send signal %d to process.', $signal));
        }
    }

    /** @throws \RuntimeException when output cannot be read */
    public function complete(): ProcessResult
    {
        try {
            $this->closeInput();
            $this->drain();
            $exitCode = \proc_close($this->process);
            $this->finished = true;

            return $this->result($exitCode);
        } catch (\Throwable $failure) {
            $this->terminate();

            throw $failure;
        }
    }

    /**
     * @throws \InvalidArgumentException when the timeout is not finite
     * @throws \RuntimeException when the process does not exit before the deadline
     */
    public function wait(float $timeoutSeconds): ProcessResult
    {
        $deadline = $this->deadline($timeoutSeconds);

        try {
            while ($this->monotonicTime() < $deadline) {
                $this->pump();
                $status = \proc_get_status($this->process);

                if (!$status['running']) {
                    $this->drainAvailable();
                    $closedExitCode = \proc_close($this->process);
                    $this->finished = true;

                    return $this->result($status['exitcode'] >= 0 ? $status['exitcode'] : $closedExitCode);
                }

                \usleep(20_000);
            }
        } catch (\Throwable $failure) {
            $this->terminate();

            throw $failure;
        }

        throw new \RuntimeException(\sprintf(
            'Timed out after %.1fs waiting for process to exit.',
            $timeoutSeconds,
        ));
    }

    public function terminate(): void
    {
        if ($this->finished) {
            return;
        }

        foreach (\array_keys($this->pipes) as $index) {
            $this->closePipe($index);
        }

        $status = \proc_get_status($this->process);

        if ($status['running']) {
            ErrorTrap::run(fn() => \proc_terminate($this->process, 9));
        }

        ErrorTrap::run(fn() => \proc_close($this->process));
        $this->finished = true;
    }

    /**
     * @throws \RuntimeException when output cannot be read
     */
    private function drain(): void
    {
        while (($read = $this->outputPipes()) !== []) {
            $write = null;
            $except = null;
            $ready = \stream_select($read, $write, $except, null);

            if ($ready === false) {
                throw new \RuntimeException('Could not read process output.');
            }

            $this->capture($read);
        }
    }

    /** @throws \RuntimeException when output cannot be read */
    private function drainAvailable(): void
    {
        while (($read = $this->outputPipes()) !== []) {
            $write = null;
            $except = null;
            $ready = \stream_select($read, $write, $except, 0, 0);

            if ($ready === false) {
                throw new \RuntimeException('Could not read process output.');
            }

            if ($ready === 0) {
                $this->closePipe(1);
                $this->closePipe(2);

                return;
            }

            $this->capture($read);
        }
    }

    /**
     * @param list<resource> $read
     */
    private function capture(array $read): void
    {
        foreach ($read as $stream) {
            $index = $stream === ($this->pipes[1] ?? null) ? 1 : 2;
            $chunk = \stream_get_contents($stream);

            if (\is_string($chunk)) {
                if ($index === 1) {
                    $this->stdout .= $chunk;
                } else {
                    $this->stderr .= $chunk;
                }
            }

            if (\feof($stream)) {
                $this->closePipe($index);
            }
        }
    }

    /**
     * @return list<resource>
     */
    private function outputPipes(): array
    {
        return \array_values(\array_intersect_key($this->pipes, [1 => true, 2 => true]));
    }

    private function closePipe(int $index): void
    {
        $pipe = $this->pipes[$index] ?? null;

        if (\is_resource($pipe)) {
            ErrorTrap::run(static fn() => \fclose($pipe));
        }

        unset($this->pipes[$index]);
    }

    private function result(int $exitCode): ProcessResult
    {
        return new ProcessResult(
            $exitCode,
            $this->normalize($this->stdout),
            $this->normalize($this->stderr),
        );
    }

    private function normalize(string $output): string
    {
        return \rtrim(\str_replace("\r\n", "\n", $output), "\n");
    }

    private function deadline(float $timeoutSeconds): float
    {
        if (!\is_finite($timeoutSeconds)) {
            throw new \InvalidArgumentException('Subprocess timeout must be finite.');
        }

        return $this->monotonicTime() + $timeoutSeconds;
    }

    private function monotonicTime(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
