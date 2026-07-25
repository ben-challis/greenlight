<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Interactive output (live window and cursor control) requires a TTY without
 * --no-ansi and without a truthy CI variable. Colour also requires NO_COLOR
 * to be unset or empty.
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
