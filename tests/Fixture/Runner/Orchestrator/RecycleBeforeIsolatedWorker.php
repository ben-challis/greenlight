<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Orchestrator;

use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Doubles\Fake;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\Worker\WorkerProcess;

final readonly class RecycleBeforeIsolatedWorker implements Fake
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
        $assignment = $channel->receive(5.0);

        if (!$assignment instanceof Assign) {
            $channel->close();

            return 3;
        }

        $channel->send(new Recycling(
            RecycleReason::TestCount,
            \array_map(static fn(PlanEntry $entry): TestId => $entry->id, $assignment->slice->entries),
            new ResultSummary(),
        ));
        $channel->close();

        return 0;
    }
}
