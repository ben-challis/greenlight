<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Orchestrator;

use Greenlight\Doubles\Fake;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\Worker\WorkerProcess;

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
            return new WorkerProcess()->run($address, $workerId, $token);
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
