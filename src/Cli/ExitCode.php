<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Plugin\CommandOutcome;
use Greenlight\Plugin\CommandResult;

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
        return new self(match ($result->outcome()) {
            CommandOutcome::Success => 0,
            CommandOutcome::Failure => 1,
            CommandOutcome::UsageError => 64,
            CommandOutcome::Interrupted => 128 + $result->interruptionSignal(),
        });
    }

    public function value(): int
    {
        return $this->value;
    }
}
