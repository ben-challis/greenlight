<?php

declare(strict_types=1);

namespace Greenlight\Cli\Output;

/**
 * Identifies one result that a Greenlight command returns. Converts the result
 * to an integer at a process or plugin seam.
 *
 * @internal
 */
enum ExitCode
{
    case Success;
    case Failure;
    case Usage;
    case Interrupted;
    case Terminated;

    /** @return 0|1|64|130|143 */
    public function toInt(): int
    {
        return match ($this) {
            self::Success => 0,
            self::Failure => 1,
            self::Usage => 64,
            self::Interrupted => 130,
            self::Terminated => 143,
        };
    }

    public static function fromInt(int $value): self
    {
        foreach (self::cases() as $exitCode) {
            if ($exitCode->toInt() === $value) {
                return $exitCode;
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            'Exit code %d does not identify a Greenlight command result.',
            $value,
        ));
    }
}
