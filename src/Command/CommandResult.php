<?php

declare(strict_types=1);

namespace Greenlight\Command;

/**
 * Contains the result that a Greenlight command returns.
 */
final readonly class CommandResult
{
    private const string SUCCESS = 'success';

    private const string FAILURE = 'failure';

    private const string USAGE_ERROR = 'usage-error';

    private const string INTERRUPTED = 'interrupted';

    private function __construct(
        private string $outcome,
        private ?int $signal = null,
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

    public function interruptionSignal(): ?int
    {
        return $this->signal;
    }
}
