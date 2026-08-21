<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Config\ConfigLoader;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\WireError;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\ChannelEnvironment;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\Bootstrap;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Ready;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * The hidden __worker command has no compatibility guarantee.
 *
 * @internal
 */
final readonly class WorkerProcess
{
    private const float RECEIVE_POLL_SECONDS = 30.0;

    public function __construct(
        private float $receivePollSeconds = self::RECEIVE_POLL_SECONDS,
    ) {}

    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     * @throws ProtocolError
     */
    public function run(string $address, string $workerId, string $token): int
    {
        // The terminal sends Ctrl+C to the complete process group. Workers
        // ignore SIGINT. Thus, the orchestrator can control an orderly drain.
        // Crash containment does not report active tests as crashes from SIGINT.
        $interruptHandler = \function_exists('pcntl_signal_get_handler')
            ? \pcntl_signal_get_handler(\SIGINT)
            : null;

        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(\SIGINT, \SIG_IGN);
        }

        try {
            return $this->runWhileIgnoringInterrupt($address, $workerId, $token);
        } finally {
            if ($interruptHandler !== null && \function_exists('pcntl_signal')) {
                \pcntl_signal(\SIGINT, $interruptHandler);
            }
        }
    }

    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     * @throws ProtocolError
     */
    private function runWhileIgnoringInterrupt(string $address, string $workerId, string $token): int
    {
        $stream = ErrorTrap::run(static function () use ($address, &$errorCode, &$errorMessage) {
            return \stream_socket_client($address, $errorCode, $errorMessage, 10.0);
        });

        if ($stream === false) {
            \fwrite(\STDERR, \sprintf("The worker did not connect to %s: %s\n", $address, $errorMessage));

            return 1;
        }

        $channel = new SocketChannel($stream);
        $pid = \getmypid();
        $channel->send(new Hello($workerId, $token, $pid === false ? 1 : \max(1, $pid)));

        // Build plugins during bootstrap and reuse them for later assignments.
        // Thus, plugin construction occurs one time for each worker.
        $scopes = null;

        try {
            while (true) {
                $message = $channel->receive($this->receivePollSeconds);

                if (!$message instanceof Message) {
                    if (!$channel->isEof()) {
                        // The resource scheduler can keep an idle worker without
                        // a message. No time limit applies while it waits for capacity.
                        continue;
                    }

                    return 0;
                }

                if ($message instanceof Drain) {
                    return 0;
                }

                if (!$message instanceof Bootstrap) {
                    if ($message instanceof Assign) {
                        throw ProtocolError::assignmentBeforeBootstrap();
                    }

                    continue;
                }

                if (ChannelEnvironment::parse(\getenv('GREENLIGHT_CHANNEL')) !== $message->channel) {
                    throw ProtocolError::bootstrapChannelMismatch();
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
                $registry = DefaultServices::registry(
                    $plugins,
                    $message->resources,
                    $message->generatedCodeDirectory,
                    $message->temporaryDirectory,
                );
                $scopes = new HarnessScopes($registry, $plugins->serviceResolvers());

                $finalMessage = $plugins->runWorker(function () use (
                    $channel,
                    $plugins,
                    $registry,
                    $scopes,
                    $workerId,
                ): ?Message {
                    try {
                        return $this->runAssignments($channel, $plugins, $registry, $scopes, $workerId);
                    } finally {
                        $scopes->closeWorker();
                    }
                });

                if ($finalMessage instanceof Message) {
                    $channel->send($finalMessage);
                }

                return 0;
            }
        } catch (\Throwable $threw) {
            try {
                $channel->send(new Fatal(ThrowableDetail::fromThrowable($threw)));
            } catch (\Throwable) {
                // This is the final report attempt. No action is possible if
                // the channel is gone.
            }

            return 1;
        } finally {
            $scopes?->closeWorker();
            $channel->close();
        }
    }

    /**
     * Runs assignments after bootstrap. The returned message is sent only
     * after every worker runtime boundary closes successfully.
     *
     * @throws WireError
     * @throws ProtocolError
     */
    private function runAssignments(
        SocketChannel $channel,
        PluginRegistry $plugins,
        HarnessRegistry $registry,
        HarnessScopes $scopes,
        string $workerId,
    ): ?Message {
        $executedTotal = 0;
        $artifactStore = null;

        $channel->send(new Ready());

        while (true) {
            $message = $channel->receive($this->receivePollSeconds);

            if (!$message instanceof Message) {
                if (!$channel->isEof()) {
                    continue;
                }

                return null;
            }

            if ($message instanceof Drain) {
                return null;
            }

            if ($message instanceof Bootstrap) {
                throw ProtocolError::duplicateBootstrap();
            }

            if (!$message instanceof Assign) {
                continue;
            }

            $collector = null;

            if ($message->coverageInclude !== null) {
                // A collector cannot observe the code that creates it. The
                // coverage acceptance tests exercise this bootstrap path.
                // @codeCoverageIgnoreStart
                $collector = CoverageCollector::create(
                    new CoverageSettings($message->coverageInclude, $message->coverageDriver),
                );
                // @codeCoverageIgnoreEnd
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
                $message->stopAfterFailures,
                new WorkerBudget($message->recycleAfterTests, $message->recycleAboveMemoryBytes),
                static fn(): bool => $channel->poll() instanceof Drain,
                $scopes,
                static function (TestId $id, int $attempt) use ($channel): void {
                    $channel->send(new AttemptStarted($id, $attempt));
                },
            );

            $coverage = $collector?->stop();

            if ($outcome->recycleReason instanceof RecycleReason) {
                return new Recycling(
                    $outcome->recycleReason,
                    $outcome->remaining,
                    $outcome->summary,
                    $coverage,
                );
            }

            $executedTotal += $outcome->summary->total();
            $wantsRecycle = null;

            if ($message->recycleAfterTests !== null && $executedTotal >= $message->recycleAfterTests) {
                $wantsRecycle = RecycleReason::TestCount;
            } elseif ($message->recycleAboveMemoryBytes !== null && \memory_get_usage(true) >= $message->recycleAboveMemoryBytes) {
                $wantsRecycle = RecycleReason::Memory;
            }

            $done = new Done(
                $outcome->summary,
                \memory_get_peak_usage(true),
                $coverage,
                $outcome->leaks,
                $wantsRecycle,
            );

            if ($outcome->drained || $wantsRecycle instanceof RecycleReason) {
                return $done;
            }

            $channel->send($done);
        }
    } // @codeCoverageIgnore
}
