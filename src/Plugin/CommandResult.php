<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Contains the result that a Greenlight command returns.
 * An interrupted result contains a signal number from 1 through 127.
 */
final readonly class CommandResult
{
    /** @param int<1, 127>|null $interruptionSignal */
    private function __construct(
        private CommandOutcome $outcome,
        private ?int $interruptionSignal = null,
    ) {}

    public static function success(): self
    {
        return new self(CommandOutcome::Success);
    }

    public static function failure(): self
    {
        return new self(CommandOutcome::Failure);
    }

    public static function usage(): self
    {
        return new self(CommandOutcome::UsageError);
    }

    /** @phpstan-assert int<1, 127> $signal */
    public static function interrupted(int $signal): self
    {
        if ($signal < 1 || $signal > 127) {
            throw new \InvalidArgumentException('Signal number MUST be from 1 through 127.');
        }

        return new self(CommandOutcome::Interrupted, $signal);
    }

    /** @internal Greenlight converts command results at the CLI process seam. */
    public function outcome(): CommandOutcome
    {
        return $this->outcome;
    }

    /**
     * @internal Greenlight converts command results at the CLI process seam.
     * @return int<1, 127>
     */
    public function interruptionSignal(): int
    {
        if ($this->interruptionSignal === null) {
            throw new \LogicException('Only an interrupted command result contains a signal.');
        }

        return $this->interruptionSignal;
    }
}
