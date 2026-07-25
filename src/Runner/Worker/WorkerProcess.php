<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Config\ConfigLoader;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Bootstrap;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Ready;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * Runs the main loop of a spawned worker process.
 *
 * run() connects, authenticates, executes assigned slices while streaming
 * events, recycles the worker when its budget is exhausted, and drains on
 * request.
 *
 * Invoked through the hidden __worker command, which carries no
 * compatibility promise.
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
        // The terminal delivers Ctrl+C to the whole process group. Workers
        // ignore SIGINT so the orchestrator can drive an orderly drain
        // instead of crash containment attributing in-flight tests to a
        // dead worker.
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(\SIGINT, \SIG_IGN);
        }

        $stream = ErrorTrap::run(static function () use ($address, &$errorCode, &$errorMessage) {
            return \stream_socket_client($address, $errorCode, $errorMessage, 10.0);
        });

        if ($stream === false) {
            \fwrite(\STDERR, \sprintf("Worker could not connect to %s: %s\n", $address, $errorMessage));

            return 1;
        }

        $channel = new SocketChannel($stream);
        $pid = \getmypid();
        $channel->send(new Hello($workerId, $token, $pid === false ? 1 : \max(1, $pid)));

        // Built by Bootstrap and reused for every assignment, so plugin
        // construction and bootstrap happen once per physical worker.
        $plugins = null;
        $registry = null;
        $scopes = null;
        $executedTotal = 0;

        try {
            while (true) {
                $message = $channel->receive(self::IDLE_TIMEOUT_SECONDS);

                if (!$message instanceof Message) {
                    // Idle too long or orchestrator gone; exit quietly.
                    $scopes?->closeRun();

                    return $channel->isEof() ? 0 : 1;
                }

                if ($message instanceof Drain) {
                    $scopes?->closeRun();

                    return 0;
                }

                if ($message instanceof Bootstrap) {
                    if ($plugins instanceof PluginRegistry) {
                        throw new \RuntimeException('Worker received bootstrap more than once.');
                    }

                    $rawChannel = \getenv('GREENLIGHT_CHANNEL');

                    if (!\is_string($rawChannel) || (int) $rawChannel !== $message->channel) {
                        throw new \RuntimeException('Worker bootstrap channel does not match GREENLIGHT_CHANNEL.');
                    }

                    $userPlugins = $message->configFile === null
                        ? []
                        : new ConfigLoader()->loadFile($message->configFile)->build()->plugins;
                    $plugins = PluginRegistry::forWorker($userPlugins);
                    $plugins->bootstrapWorker(new WorkerBootstrapContext(
                        $workerId,
                        new TestChannel($message->channel),
                        $message->resources,
                    ));
                    $registry = DefaultServices::registry($plugins, $message->resources);
                    $scopes = new HarnessScopes($registry, $plugins->serviceResolvers());
                    $channel->send(new Ready());

                    continue;
                }

                if (!$message instanceof Assign) {
                    continue;
                }

                if (!$plugins instanceof PluginRegistry || !$registry instanceof HarnessRegistry || !$scopes instanceof HarnessScopes) {
                    throw new \RuntimeException('Worker received an assignment before bootstrap completed.');
                }

                $collector = null;

                if ($message->coverageInclude !== null) {
                    $collector = CoverageCollector::create(
                        new CoverageSettings($message->coverageInclude, $message->coverageDriver),
                    );
                }

                $collector?->start();

                $leakDetector = $message->detectLeaks ? new LeakDetector() : null;

                $outcome = new Worker($registry, $plugins, $leakDetector, $workerId, $message->policy)->run(
                    $message->slice,
                    new SocketEventSink($channel),
                    null,
                    new WorkerBudget($message->recycleAfterTests, $message->recycleAboveMemoryBytes),
                    static fn(): bool => $channel->poll() instanceof Drain,
                    $scopes,
                );

                $coverage = $collector?->stop();

                if ($outcome->recycleReason instanceof RecycleReason) {
                    $scopes->closeRun();
                    $channel->send(new Recycling($outcome->recycleReason, $outcome->remaining, $coverage));

                    return 0;
                }

                $executedTotal += $outcome->summary->total();
                $wantsRecycle = null;

                if ($message->recycleAfterTests !== null && $executedTotal >= $message->recycleAfterTests) {
                    $wantsRecycle = RecycleReason::TestCount;
                } elseif ($message->recycleAboveMemoryBytes !== null && \memory_get_usage(true) >= $message->recycleAboveMemoryBytes) {
                    $wantsRecycle = RecycleReason::Memory;
                }

                $channel->send(new Done($outcome->summary, \memory_get_peak_usage(true), $coverage, $outcome->leaks, $wantsRecycle));

                if ($outcome->drained || $wantsRecycle instanceof RecycleReason) {
                    $scopes->closeRun();

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
            $scopes?->closeRun();
            $channel->close();
        }
    }
}
