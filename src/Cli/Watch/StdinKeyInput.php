<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Cli\Terminal;
use Greenlight\Core\ErrorTrap;

/**
 * Reads single keys from stdin without blocking.
 *
 * On an interactive terminal the constructor disables canonical mode so keys
 * arrive without a newline; restore() re-enables it when the loop ends.
 *
 * A piped stdin works as-is, which is what the acceptance tests drive.
 *
 * @internal
 */
final class StdinKeyInput implements KeyInput
{
    private bool $rawMode = false;

    public function __construct()
    {
        \stream_set_blocking(\STDIN, false);

        if (Terminal::isTty(\STDIN) && \function_exists('shell_exec')) {
            ErrorTrap::run(static fn(): string|false|null => \shell_exec('stty -icanon -echo < /dev/tty 2> /dev/null'));
            $this->rawMode = true;
        }
    }

    #[\Override]
    public function poll(): ?string
    {
        $key = ErrorTrap::run(static fn(): string|false => \fread(\STDIN, 1));

        return \is_string($key) && $key !== '' ? $key : null;
    }

    public function restore(): void
    {
        if ($this->rawMode) {
            ErrorTrap::run(static fn(): string|false|null => \shell_exec('stty icanon echo < /dev/tty 2> /dev/null'));
            $this->rawMode = false;
        }
    }
}
