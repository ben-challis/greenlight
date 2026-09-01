<?php

declare(strict_types=1);

namespace Greenlight\Command;

/**
 * Contains the result that a Greenlight command returns.
 */
final readonly class ExitCode
{
    private function __construct(private int $value) {}

    public static function success(): self
    {
        return new self(0);
    }

    public static function failure(): self
    {
        return new self(1);
    }

    public static function usage(): self
    {
        return new self(64);
    }

    public static function signal(int $signal): self
    {
        if ($signal < 1 || $signal > 127) {
            throw new \InvalidArgumentException('Signal number MUST be from 1 through 127.');
        }

        return new self(128 + $signal);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 0 || $value > 255) {
            throw new \InvalidArgumentException('Exit code MUST be from 0 through 255.');
        }

        return new self($value);
    }

    public function isSuccess(): bool
    {
        return $this->value === 0;
    }

    public function toInt(): int
    {
        return $this->value;
    }
}
