<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Worker;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Config\ConfigLoader;
use Greenlight\Coverage\Collection\CoverageCollector;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Execution\Artifact\ArtifactSession;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Execution\Worker\ChannelEnvironment;
use Greenlight\Execution\Worker\HarnessServiceDisposal;
use Greenlight\Execution\Worker\LeakDetector;
use Greenlight\Execution\Worker\ResultPolicyPlugin;
use Greenlight\Execution\Worker\StandardHarnessPlugin;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Execution\Worker\WorkerError;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Plugin\CommandResult;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestChannel;
use Greenlight\Test\TestId;

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
        private bool $isolateProcessGroup = false,
    ) {}

    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     * @throws ProtocolError
     */
    public function run(string $address, string $workerId, string $token): CommandResult
    {
        // Keep the worker and its subprocesses outside the terminal process group.
        // Thus, terminal SIGINT reaches only the orchestrator.
        if ($this->isolateProcessGroup && \function_exists('posix_setpgid')) {
            ErrorTrap::run(static fn() => \posix_setpgid(0, 0));
        }

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
    private function runWhileIgnoringInterrupt(string $address, string $workerId, string $token): CommandResult
    {
        $stream = ErrorTrap::run(static function () use ($address, &$errorCode, &$errorMessage) {
            return \stream_socket_client($address, $errorCode, $errorMessage, 10.0);
        });

        if ($stream === false) {
            \fwrite(\STDERR, \sprintf("The worker did not connect to %s: %s\n", $address, $errorMessage));

            return CommandResult::failure();
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

                    return CommandResult::success();
                }

                if ($message instanceof Drain) {
                    return CommandResult::success();
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

                $pluginDefinitions = $message->configFile === null
                    ? []
                    : new ConfigLoader()->loadFile($message->configFile)->build()->execution->plugins;
                $bootstrap = new WorkerBootstrapContext(
                    $workerId,
                    new TestChannel($message->channel),
                    $message->resources,
                );
                $plugins = WorkerPluginRuntime::fromDefinitions($pluginDefinitions, [
                    new StandardHarnessPlugin(
                        $message->resources,
                        $bootstrap->channel,
                        $message->generatedCodeDirectory,
                        $message->temporaryDirectory,
                    ),
                    ...($message->policy instanceof ResultPolicy
                        ? [new ResultPolicyPlugin($message->policy)]
                        : []),
                ]);
                $scopes = $plugins->prepareWorker(
                    $bootstrap,
                    [],
                );

                $finalMessage = $plugins->runWorker(fn(): ?Message => HarnessServiceDisposal::runAndClose(
                    $scopes,
                    fn(): ?Message => $this->runAssignments($channel, $plugins, $scopes, $workerId),
                ));

                if ($finalMessage instanceof Message) {
                    $channel->send($finalMessage);
                }

                return CommandResult::success();
            }
        } catch (\Throwable $threw) {
            try {
                $channel->send(new Fatal(ThrowableDetail::fromThrowable($threw)));
            } catch (\Throwable) {
                // This is the final report attempt. No action is possible if
                // the channel is gone.
            }

            return CommandResult::failure();
        } finally {
            $scopes?->closeWorker();
            $channel->close();
        }
    }

    /**
     * Runs assignments after bootstrap. The returned message is sent only
     * after every worker runtime boundary closes successfully.
     *
     * @throws WireCommunicationFailed
     * @throws ProtocolError
     * @throws WorkerError
     */
    private function runAssignments(
        SocketChannel $channel,
        WorkerPluginRuntime $plugins,
        HarnessScopes $scopes,
        string $workerId,
    ): ?Message {
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
                [],
                $plugins,
                $leakDetector,
                $workerId,
                $artifactStore,
            )->run(
                $message->slice,
                new SocketEventSink($channel),
                $message->stopAfterFailures,
                static fn(): bool => $channel->poll() instanceof Drain,
                $scopes,
                static function (TestId $id, int $attempt) use ($channel): void {
                    $channel->send(new AttemptStarted($id, $attempt));
                },
            );

            $coverage = $collector?->stop();

            $done = new Done(
                $outcome->summary,
                \memory_get_peak_usage(true),
                $coverage,
                $outcome->leaks,
            );

            if ($outcome->drained) {
                return $done;
            }

            $channel->send($done);
        }
    } // @codeCoverageIgnore
}
