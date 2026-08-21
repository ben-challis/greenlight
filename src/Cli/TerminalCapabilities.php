<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Interactive output requires a TTY, no --no-ansi flag, and no truthy CI
 * variable. Interactive output includes the live window and cursor control.
 * --ansi can enable color without interactive output. NO_COLOR and --no-ansi
 * have priority over --ansi.
 *
 * @internal
 */
final readonly class TerminalCapabilities
{
    public function __construct(
        public bool $interactive,
        public bool $color,
    ) {}

    /**
     * @param array<string, string|false> $env getenv() snapshot for CI and NO_COLOR
     */
    public static function detect(bool $stdoutIsTty, array $env, bool $noAnsiFlag, bool $ansiFlag = false): self
    {
        $interactive = $stdoutIsTty && !$noAnsiFlag && !self::truthy($env['CI'] ?? false);
        $noColorValue = $env['NO_COLOR'] ?? false;
        $noColor = $noColorValue !== false && $noColorValue !== '';
        $color = !$noAnsiFlag && !$noColor && ($interactive || $ansiFlag);

        return new self($interactive, $color);
    }

    private static function truthy(string|false $value): bool
    {
        return $value !== false && $value !== '' && !\in_array(\strtolower($value), ['0', 'false'], true);
    }
}
