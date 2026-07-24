<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/**
 * The observable result of a completed subprocess.
 *
 * stdout and stderr are captured separately. output() and outputLines()
 * combine them, stdout first, for assertions that only care about the full
 * diagnostic text; the stream-specific accessors support exact assertions
 * that must ignore extension noise on stderr.
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function output(): string
    {
        if ($this->stdout === '') {
            return $this->stderr;
        }

        if ($this->stderr === '') {
            return $this->stdout;
        }

        return $this->stdout . "\n" . $this->stderr;
    }

    /**
     * @return list<string>
     */
    public function stdoutLines(): array
    {
        return $this->lines($this->stdout);
    }

    /**
     * @return list<string>
     */
    public function stderrLines(): array
    {
        return $this->lines($this->stderr);
    }

    /**
     * @return list<string>
     */
    public function outputLines(): array
    {
        return [...$this->stdoutLines(), ...$this->stderrLines()];
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return $output === '' ? [] : \explode("\n", $output);
    }
}
