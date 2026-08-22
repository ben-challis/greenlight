<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\ConnectedStreamPair;

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

        $pair = ConnectedStreamPair::open();

        $channel = new SocketChannel($pair[0]);
        $previousAsyncSignals = \pcntl_async_signals(true);
        $previousHandler = \pcntl_signal_get_handler(\SIGUSR1);
        \pcntl_signal(\SIGUSR1, static function (): void {}, false);
        $parentPid = \getmypid();

        Expect::that($parentPid)
            ->because('The test worker MUST have a process ID.')
            ->toBeInt();

        $childPid = \pcntl_fork();

        if ($childPid === -1) {
            Fail::because('Expected to fork the signal helper process.');
        }

        if ($childPid === 0) {
            while (\posix_kill($parentPid, \SIGUSR1)) {
                \usleep(20_000);
            }

            exit(1);
        }

        $status = 0;

        try {
            $received = $channel->receive(30.0);
            $eof = $channel->isEof();
        } finally {
            $stopped = \posix_kill($childPid, \SIGTERM);
            $waited = \pcntl_waitpid($childPid, $status);
            \pcntl_signal(\SIGUSR1, $previousHandler);
            \pcntl_async_signals($previousAsyncSignals);
            $channel->close();
            \fclose($pair[1]);
        }

        Expect::that($status)
            ->because('The signal helper MUST provide a process status.')
            ->toBeInt();

        Expect::that($received)
            ->because('a signal-interrupted select MUST end the receive attempt')
            ->toBeNull();
        Expect::that($eof)
            ->because('an interrupted select MUST not claim that the peer closed')
            ->toBeFalse();
        Expect::that($waited)
            ->because('the signal helper MUST finish before the test exits')
            ->toBe($childPid);
        Expect::that($stopped)
            ->because('the signal helper MUST remain active until the receive attempt ends')
            ->toBeTrue();
        Expect::that(\pcntl_wifsignaled($status))
            ->because('the test MUST stop the signal helper after the receive attempt ends')
            ->toBeTrue();
        Expect::that(\pcntl_wtermsig($status))
            ->toBe(\SIGTERM);
    }
}
