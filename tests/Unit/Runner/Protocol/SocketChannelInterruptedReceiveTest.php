<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\SocketChannel;

final readonly class SocketChannelInterruptedReceiveTest
{
    #[Test]
    #[Isolated]
    #[Timeout(2.0)]
    public function anInterruptedSelectEndsTheReceiveWithoutClosingTheChannel(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            throw new SkipTest('Process control is not available.');
        }

        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

        if ($pair === false || \count($pair) !== 2 || !isset($pair[0], $pair[1])) {
            Fail::because('Expected stream_socket_pair() to create a connected socket pair.');
        }

        $channel = new SocketChannel($pair[0]);
        $previousAsyncSignals = \pcntl_async_signals(true);
        $previousHandler = \pcntl_signal_get_handler(\SIGUSR1);
        \pcntl_signal(\SIGUSR1, static function (): void {}, false);
        $parentPid = \getmypid();

        if ($parentPid === false) {
            Fail::because('Expected the test worker to have a process ID.');
        }

        $childPid = \pcntl_fork();

        if ($childPid === -1) {
            Fail::because('Expected to fork the signal helper process.');
        }

        if ($childPid === 0) {
            \usleep(20_000);
            exit(\posix_kill($parentPid, \SIGUSR1) ? 0 : 1);
        }

        $status = 0;

        try {
            $received = $channel->receive(30.0);
            $eof = $channel->isEof();
        } finally {
            $waited = \pcntl_waitpid($childPid, $status);
            \pcntl_signal(\SIGUSR1, $previousHandler);
            \pcntl_async_signals($previousAsyncSignals);
            $channel->close();
            \fclose($pair[1]);
        }

        Expect::that($received)
            ->because('a signal-interrupted select MUST end the receive attempt')
            ->toBeNull()
            ->and($eof)
            ->because('an interrupted select MUST not claim that the peer closed')
            ->toBeFalse()
            ->and($waited)
            ->because('the signal helper MUST finish before the test exits')
            ->toBe($childPid)
            ->and($status)
            ->because('the signal helper MUST deliver SIGUSR1 successfully')
            ->toBe(0);
    }
}
