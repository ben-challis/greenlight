<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Config\ConfigLoader;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
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
 * The hidden __worker command carries no compatibility promise.
 *
 * @internal
 */
final readonly class WorkerProcess
{
    private const float RECEIVE_POLL_SECONDS = 30.0;

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

        // Built on the first assignment and reused for every later one, so
        // plugin construction happens once per worker and per-run harness
        // services keep worker-lifetime semantics across assignments.
        $plugins = null;
        $registry = null;
        $scopes = null;
        $executedTotal = 0;
        $artifactStore = null;

        try {
            while (true) {
                $message = $channel->receive(self::RECEIVE_POLL_SECONDS);

                if (!$message instanceof Message) {
                    if (!$channel->isEof()) {
                        // Resource-aware scheduling may intentionally leave an
                        // idle worker without a message for an arbitrary time.
                        continue;
                    }

                    $scopes?->closeRun();

                    return 0;
                }

                if ($message instanceof Drain) {
                    $scopes?->closeRun();

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

                if (!$plugins instanceof PluginRegistry || !$registry instanceof HarnessRegistry || !$scopes instanceof HarnessScopes) {
                    $userPlugins = $message->configFile === null
                        ? []
                        : new ConfigLoader()->loadFile($message->configFile)->build()->plugins;
                    $plugins = PluginRegistry::forWorker($userPlugins);
                    $registry = DefaultServices::registry($plugins);
                    $scopes = new HarnessScopes($registry, $plugins->serviceResolvers());
                }

                if (!$artifactStore instanceof ArtifactStore
                    && $message->artifactSession instanceof ArtifactSession
                    && $message->artifactConfiguration instanceof ArtifactConfiguration
                ) {
                    $artifactStore = ArtifactStore::fromSession(
                        $message->artifactSession,
                        $message->artifactConfiguration,
                    );
                }

                $collector?->start();

                $leakDetector = $message->detectLeaks ? new LeakDetector() : null;

                $outcome = new Worker(
                    $registry,
                    $plugins,
                    $leakDetector,
                    $workerId,
                    $message->policy,
                    $artifactStore,
                )->run(
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
            $channel->close();
        }
    }
}
