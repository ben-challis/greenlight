<?php

declare(strict_types=1);

namespace Greenlight\Cli\Output;

/**
 * Contains the integer result that a Greenlight command returns. Converts the
 * result to an integer at a process or plugin seam.
 *
 * @internal
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

    public static function fromInt(int $value): self
    {
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
