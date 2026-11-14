<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Cli\Output\Terminal;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Reads available single keys from standard input.
 *
 * On an interactive terminal, the constructor disables canonical mode. Thus,
 * keys arrive without a newline. restore() enables canonical mode when the
 * loop ends.
 *
 * The constructor also makes standard input non-blocking. The shell shares
 * this file status with the next command, so restore() sets the original
 * blocking mode again.
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

    private const int MAXIMUM_DISCARDED_KEYS = 65_536;

    private bool $rawMode = false;

    private bool $restoreBlocking;

    /** @var \Closure(bool): mixed */
    private readonly \Closure $configureBlocking;

    /** @var \Closure(): (string|false) */
    private readonly \Closure $read;

    /** @var (\Closure(string): (string|false|null))|null */
    private readonly ?\Closure $runShellCommand;

    /**
     * @param (\Closure(bool): mixed)|null $configureBlocking
     * @param (\Closure(): bool)|null $isTty
     * @param (\Closure(): (string|false))|null $read
     * @param (\Closure(string): (string|false|null))|null $runShellCommand
     * @param (\Closure(): bool)|null $isBlocking
     *
     * @throws \RuntimeException when standard input cannot become non-blocking
     */
    public function __construct(
        ?\Closure $configureBlocking = null,
        ?\Closure $isTty = null,
        ?\Closure $read = null,
        ?\Closure $runShellCommand = null,
        ?\Closure $isBlocking = null,
    ) {
        $configureBlocking ??= static fn(bool $blocking): bool => \stream_set_blocking(\STDIN, $blocking);
        $isTty ??= static fn(): bool => Terminal::isTty(\STDIN);
        $read ??= static fn(): string|false => \fread(\STDIN, 1);
        $isBlocking ??= static fn(): bool => \stream_get_meta_data(\STDIN)['blocked'];
        $runShellCommand ??= \function_exists('shell_exec')
            ? \shell_exec(...)
            : null;

        $this->read = $read;
        $this->runShellCommand = $runShellCommand;
        $this->configureBlocking = $configureBlocking;
        $wasBlocking = $isBlocking();

        if ($configureBlocking(false) === false) {
            throw new \RuntimeException('Greenlight could not make standard input non-blocking.');
        }

        $this->restoreBlocking = $wasBlocking;

        if ($isTty() && $runShellCommand instanceof \Closure) {
            ErrorTrap::run(static fn() => $runShellCommand(self::ENABLE_RAW_MODE_COMMAND));
            $this->rawMode = true;
        }
    }

    #[\Override]
    public function poll(): ?string
    {
        $key = ErrorTrap::run($this->read);

        return \is_string($key) && $key !== '' ? $key : null;
    }

    #[\Override]
    public function discardPending(): bool
    {
        $quit = false;

        for ($count = 0; $count < self::MAXIMUM_DISCARDED_KEYS && ($key = $this->poll()) !== null; ++$count) {
            $quit = $quit || $key === 'q';
        }

        return $quit;
    }

    public function restore(): void
    {
        if ($this->restoreBlocking) {
            ErrorTrap::run(fn() => ($this->configureBlocking)(true));
            $this->restoreBlocking = false;
        }

        if ($this->rawMode && $this->runShellCommand instanceof \Closure) {
            ErrorTrap::run(
                fn() => ($this->runShellCommand)(self::RESTORE_CANONICAL_MODE_COMMAND),
            );
            $this->rawMode = false;
        }
    }
}
