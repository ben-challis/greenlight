<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Cli\Terminal;
use Greenlight\Core\ErrorTrap;

/**
 * Reads available single keys from standard input.
 *
 * On an interactive terminal, the constructor disables canonical mode. Thus,
 * keys arrive without a newline. restore() enables canonical mode when the
 * loop ends.
 *
 * Standard input from a pipe does not require a mode change. The acceptance
 * tests use this form.
 *
 * @internal
 */
final class StdinKeyInput implements KeyInput
{
    private const string ENABLE_RAW_MODE_COMMAND = 'stty -icanon -echo < /dev/tty 2> /dev/null';

    private const string RESTORE_CANONICAL_MODE_COMMAND = 'stty icanon echo < /dev/tty 2> /dev/null';

    private bool $rawMode = false;

    /** @var \Closure(): (string|false) */
    private readonly \Closure $read;

    /** @var (\Closure(string): (string|false|null))|null */
    private readonly ?\Closure $runShellCommand;

    /**
     * @param (\Closure(bool): mixed)|null $configureBlocking
     * @param (\Closure(): bool)|null $isTty
     * @param (\Closure(): (string|false))|null $read
     * @param (\Closure(string): (string|false|null))|null $runShellCommand
     *
     * @throws \RuntimeException when standard input cannot become non-blocking
     */
    public function __construct(
        ?\Closure $configureBlocking = null,
        ?\Closure $isTty = null,
        ?\Closure $read = null,
        ?\Closure $runShellCommand = null,
    ) {
        $configureBlocking ??= static fn(bool $blocking): bool => \stream_set_blocking(\STDIN, $blocking);
        $isTty ??= static fn(): bool => Terminal::isTty(\STDIN);
        $read ??= static fn(): string|false => \fread(\STDIN, 1);
        $runShellCommand ??= \function_exists('shell_exec')
            ? \shell_exec(...)
            : null;

        $this->read = $read;
        $this->runShellCommand = $runShellCommand;

        if ($configureBlocking(false) === false) {
            throw new \RuntimeException('Greenlight could not make standard input non-blocking.');
        }

        if ($isTty() && $runShellCommand instanceof \Closure) {
            ErrorTrap::run(static fn(): string|false|null => $runShellCommand(self::ENABLE_RAW_MODE_COMMAND));
            $this->rawMode = true;
        }
    }

    #[\Override]
    public function poll(): ?string
    {
        $key = ErrorTrap::run($this->read);

        return \is_string($key) && $key !== '' ? $key : null;
    }

    public function restore(): void
    {
        if ($this->rawMode && $this->runShellCommand instanceof \Closure) {
            ErrorTrap::run(
                fn(): string|false|null => ($this->runShellCommand)(self::RESTORE_CANONICAL_MODE_COMMAND),
            );
            $this->rawMode = false;
        }
    }
}
