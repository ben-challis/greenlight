<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Orchestrator;

use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Doubles\Fake;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Ready;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\Worker\WorkerProcess;

final readonly class RecycleUntilProgressWorker implements Fake
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
        $channel->receive(5.0);
        $channel->send(new Ready());
        $assignment = $channel->receive(5.0);

        if (!$assignment instanceof Assign) {
            $channel->close();

            return 3;
        }

        $channel->send(new Recycling(
            RecycleReason::TestCount,
            \array_map(static fn(PlanEntry $entry) => $entry->id, $assignment->slice->entries),
            new ResultSummary(),
        ));
        $channel->close();

        $marker = \getenv('GREENLIGHT_RETIREMENT_PROGRESS_MARKER');
        $deadline = \microtime(true) + 1.5;

        while (\is_string($marker) && !\is_file($marker) && \microtime(true) < $deadline) {
            \usleep(10_000);
        }

        $log = \getenv('GREENLIGHT_RETIREMENT_LOG');

        if (\is_string($log) && $log !== '') {
            $result = \is_string($marker) && \is_file($marker) ? 'progress-observed' : 'progress-timeout';
            \file_put_contents($log, $result . "\n", \FILE_APPEND | \LOCK_EX);
        }

        return 0;
    }
}
