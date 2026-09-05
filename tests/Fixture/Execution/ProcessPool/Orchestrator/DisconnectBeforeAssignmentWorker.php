<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\ProcessPool\Orchestrator;

use Greenlight\Cli\ExitCode;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Execution\ProcessPool\Worker\WorkerProcess;

final readonly class DisconnectBeforeAssignmentWorker implements Fake
{
    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     */
    public static function run(string $address, string $workerId, string $token): int
    {
        if ($workerId !== 'w-1') {
            return ExitCode::fromCommandResult(new WorkerProcess()->run($address, $workerId, $token))->value();
        }

        $stream = \stream_socket_client($address);

        if (!\is_resource($stream)) {
            return 2;
        }

        $channel = new SocketChannel($stream);
        $pid = \getmypid();
        $channel->send(new Hello($workerId, $token, $pid === false ? 1 : \max(1, $pid)));
        \stream_socket_shutdown($stream, \STREAM_SHUT_RDWR);
        $channel->close();

        return 0;
    }
}
