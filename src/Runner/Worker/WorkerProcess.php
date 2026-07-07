<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * Main loop of a spawned worker process: connect, authenticate, execute
 * assigned slices while streaming events, recycle itself when its budget is
 * exhausted, drain on request. Invoked through the hidden __worker command,
 * which carries no compatibility promise.
 *
 * @internal
 */
final readonly class WorkerProcess
{
    private const float IDLE_TIMEOUT_SECONDS = 30.0;

    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     */
    public function run(string $address, string $workerId, string $token): int
    {
        $stream = @\stream_socket_client($address, $errorCode, $errorMessage, 10.0);

        if ($stream === false) {
            \fwrite(\STDERR, \sprintf("Worker could not connect to %s: %s\n", $address, $errorMessage));

            return 1;
        }

        $channel = new SocketChannel($stream);
        $pid = \getmypid();
        $channel->send(new Hello($workerId, $token, $pid === false ? 1 : \max(1, $pid)));

        try {
            while (true) {
                $message = $channel->receive(self::IDLE_TIMEOUT_SECONDS);

                if (!$message instanceof Message) {
                    // Idle too long or orchestrator gone; exit quietly.
                    return $channel->isEof() ? 0 : 1;
                }

                if ($message instanceof Drain) {
                    return 0;
                }

                if (!$message instanceof Assign) {
                    continue;
                }

                $collector = null;

                if ($message->coverageInclude !== null) {
                    $collector = CoverageCollector::create(
                        new CoverageSettings($message->coverageInclude, $message->coverageDriver),
                    );
                }

                $collector?->start();

                $outcome = new Worker(DefaultServices::registry())->run(
                    $message->slice,
                    new SocketEventSink($channel),
                    null,
                    new WorkerBudget($message->recycleAfterTests, $message->recycleAboveMemoryBytes),
                    static fn(): bool => $channel->poll() instanceof Drain,
                );

                $coverage = $collector?->stop();

                if ($outcome->recycleReason instanceof RecycleReason) {
                    $channel->send(new Recycling($outcome->recycleReason, $outcome->remaining, $coverage));

                    return 0;
                }

                $channel->send(new Done($outcome->summary, \memory_get_peak_usage(true), $coverage));

                if ($outcome->drained) {
                    return 0;
                }
            }
        } catch (\Throwable $threw) {
            try {
                $channel->send(new Fatal(ThrowableDetail::fromThrowable($threw)));
            } catch (\Throwable) {
                // Last gasp only; nothing more to do if the channel is gone.
            }

            return 1;
        } finally {
            $channel->close();
        }
    }
}
