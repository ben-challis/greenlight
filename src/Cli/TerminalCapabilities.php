<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * What the output stream can do, decided once per invocation.
 *
 * detect() derives the two capabilities from the TTY check, an environment
 * snapshot, and the --no-ansi flag: interactive (live window and cursor
 * control) requires a TTY without --no-ansi and without a truthy CI
 * variable; colour additionally requires NO_COLOR to be unset or empty.
 * Detection is a pure function of its inputs so the whole matrix is
 * unit-testable.
 *
 * @internal
 */
final readonly class TerminalCapabilities
{
    public function __construct(
        public bool $interactive,
        public bool $colour,
    ) {}

    /**
     * @param array<string, string|false> $env getenv() snapshot for CI and NO_COLOR
     */
    public static function detect(bool $stdoutIsTty, array $env, bool $noAnsiFlag): self
    {
        $interactive = $stdoutIsTty && !$noAnsiFlag && !self::truthy($env['CI'] ?? false);
        $noColorValue = $env['NO_COLOR'] ?? false;
        $noColor = $noColorValue !== false && $noColorValue !== '';

        return new self($interactive, $interactive && !$noColor);
    }

    private static function truthy(string|false $value): bool
    {
        return $value !== false && $value !== '' && !\in_array(\strtolower($value), ['0', 'false'], true);
    }
}
