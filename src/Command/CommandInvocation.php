<?php

declare(strict_types=1);

namespace Greenlight\Command;

/** Contains the input and output channels for one plugin command invocation. */
final readonly class CommandInvocation
{
    /**
     * @param list<string> $arguments
     * @param list<string> $argv
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    private function __construct(
        public string $command,
        public array $arguments,
        public string $workingDirectory,
        public ?string $binaryPath,
        private array $argv,
        private \Closure $out,
        private \Closure $err,
    ) {}

    /** Write exact text to standard output. */
    public function write(string $text): void
    {
        ($this->out)($text);
    }

    /** Write exact text to standard error. */
    public function writeError(string $text): void
    {
        ($this->err)($text);
    }

    /**
     * @internal Greenlight constructs command invocations.
     *
     * @param list<string> $arguments
     * @param list<string> $argv
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    public static function create(
        string $command,
        array $arguments,
        string $workingDirectory,
        ?string $binaryPath,
        array $argv,
        \Closure $out,
        \Closure $err,
    ): self {
        return new self($command, $arguments, $workingDirectory, $binaryPath, $argv, $out, $err);
    }

    /**
     * @internal Bundled commands use the Greenlight parser with the original input.
     *
     * @return list<string>
     */
    public function argv(): array
    {
        return $this->argv;
    }
}
