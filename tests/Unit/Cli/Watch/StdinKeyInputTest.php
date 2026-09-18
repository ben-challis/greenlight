<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StdinKeyInput;

use function Greenlight\expect;

final class StdinKeyInputTest
{
    #[Test]
    #[DataSet('readResults')]
    public function pollReturnsOnlyAvailableKeys(string|false $readResult, ?string $expected): void
    {
        $input = new StdinKeyInput(
            configureBlocking: static function (bool $enabled): void {},
            isTty: static fn(): bool => false,
            read: static fn(): string|false => $readResult,
        );

        expect($input->poll())
            ->because('poll returns a key only when one is available')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string|false, ?string}>
     */
    public static function readResults(): iterable
    {
        yield 'key' => ['q', 'q'];
        yield 'no bytes available' => ['', null];
        yield 'read failure' => [false, null];
    }

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

        expect($blocking)
            ->because('terminal input becomes non-blocking')
            ->toBe([false]);
        expect($commands)
            ->because('terminal input enables raw mode')
            ->toBe(['stty -icanon -echo < /dev/tty 2> /dev/null']);

        $input->restore();
        $input->restore();

        expect($commands)
            ->because('terminal input restores canonical mode exactly once')
            ->toBe([
                'stty -icanon -echo < /dev/tty 2> /dev/null',
                'stty icanon echo < /dev/tty 2> /dev/null',
            ]);
    }

    #[Test]
    public function nonTerminalInputBecomesNonBlockingWithoutChangingTerminalMode(): void
    {
        $blocking = [];
        $commands = [];
        $input = new StdinKeyInput(
            configureBlocking: static function (bool $enabled) use (&$blocking): void {
                $blocking[] = $enabled;
            },
            isTty: static fn(): bool => false,
            read: static fn(): false => false,
            runShellCommand: static function (string $command) use (&$commands): null {
                $commands[] = $command;

                return null;
            },
        );

        $input->restore();

        expect($blocking)
            ->because('non-terminal input MUST remain non-blocking')
            ->toBe([false]);
        expect($commands)
            ->because('non-terminal input does not change terminal mode')
            ->toBe([]);
    }

    #[Test]
    public function failureToDisableBlockingInputIsRejected(): void
    {
        expect()->calling(static fn(): StdinKeyInput => new StdinKeyInput(
            configureBlocking: static fn(bool $enabled): false => false,
            isTty: static fn(): bool => false,
            read: static fn(): false => false,
        ))
            ->because('watch input MUST NOT continue with a potentially blocking read')
            ->toThrow(
                \RuntimeException::class,
                message: 'Greenlight could not make standard input non-blocking.',
            );
    }
}
