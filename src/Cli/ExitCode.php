<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Command\CommandResult;

/**
 * Converts a command result to a process exit code.
 *
 * @internal
 */
final readonly class ExitCode
{
    private function __construct(private int $value) {}

    public static function fromCommandResult(CommandResult $result): self
    {
        return new self(match (true) {
            $result->isSuccessful() => 0,
            $result->isUsageError() => 64,
            $result->isInterrupted() => 128 + $result->interruptionSignal,
            default => 1,
        });
    }

    public function value(): int
    {
        return $this->value;
    }
}
