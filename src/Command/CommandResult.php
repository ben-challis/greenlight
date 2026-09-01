<?php

declare(strict_types=1);

namespace Greenlight\Command;

/**
 * Contains the result that a Greenlight command returns.
 * An interrupted result contains a signal number from 1 through 127.
 */
final readonly class CommandResult
{
    private const string SUCCESS = 'success';

    private const string FAILURE = 'failure';

    private const string USAGE_ERROR = 'usage-error';

    private const string INTERRUPTED = 'interrupted';

    /** @param int<1, 127>|null $interruptionSignal */
    private function __construct(
        private string $outcome,
        public ?int $interruptionSignal = null,
    ) {}

    public static function success(): self
    {
        return new self(self::SUCCESS);
    }

    public static function failure(): self
    {
        return new self(self::FAILURE);
    }

    public static function usage(): self
    {
        return new self(self::USAGE_ERROR);
    }

    /** @phpstan-assert int<1, 127> $signal */
    public static function interrupted(int $signal): self
    {
        if ($signal < 1 || $signal > 127) {
            throw new \InvalidArgumentException('Signal number MUST be from 1 through 127.');
        }

        return new self(self::INTERRUPTED, $signal);
    }

    public function isSuccessful(): bool
    {
        return $this->outcome === self::SUCCESS;
    }

    public function isUsageError(): bool
    {
        return $this->outcome === self::USAGE_ERROR;
    }

    /**
     * @phpstan-assert-if-true int<1, 127> $this->interruptionSignal
     * @phpstan-assert-if-false null $this->interruptionSignal
     */
    public function isInterrupted(): bool
    {
        return $this->interruptionSignal !== null;
    }
}
