<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/**
 * output() and outputLines() combine stdout and stderr in that order.
 * stdoutLines() excludes extension noise written to stderr.
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
    public function outputLines(): array
    {
        return [...$this->stdoutLines(), ...$this->lines($this->stderr)];
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return $output === '' ? [] : \explode("\n", $output);
    }
}
