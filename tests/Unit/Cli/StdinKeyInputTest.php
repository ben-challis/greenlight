<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StdinKeyInput;
use Greenlight\Expect\Expect;

final class StdinKeyInputTest
{
    #[Test]
    public function aTerminalEnablesRawModeAndRestoresItExactlyOnce(): void
    {
        $blocking = [];
        $commands = [];
        $input = new StdinKeyInput(
            configureBlocking: static function (bool $enabled) use (&$blocking): void {
                $blocking[] = $enabled;
            },
            isTty: static fn(): bool => true,
            read: static fn(): false => false,
            runShellCommand: static function (string $command) use (&$commands): null {
                $commands[] = $command;

                return null;
            },
        );

        Expect::that($blocking)
            ->because('terminal input becomes non-blocking')
            ->toBe([false])
            ->and($commands)
            ->because('terminal input enables raw mode')
            ->toBe(['stty -icanon -echo < /dev/tty 2> /dev/null']);

        $input->restore();
        $input->restore();

        Expect::that($commands)
            ->because('terminal input restores canonical mode exactly once')
            ->toBe([
                'stty -icanon -echo < /dev/tty 2> /dev/null',
                'stty icanon echo < /dev/tty 2> /dev/null',
            ]);
    }

    #[Test]
    public function nonTerminalInputDoesNotChangeTheTerminalMode(): void
    {
        $commands = [];
        $input = new StdinKeyInput(
            configureBlocking: static function (bool $enabled): void {},
            isTty: static fn(): bool => false,
            read: static fn(): false => false,
            runShellCommand: static function (string $command) use (&$commands): null {
                $commands[] = $command;

                return null;
            },
        );

        $input->restore();

        Expect::that($commands)
            ->because('non-terminal input does not change terminal mode')
            ->toBe([]);
    }
}
