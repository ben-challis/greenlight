<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Config\ConfigLoader;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Protocol\Channel;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;

/**
 * Runs one worker protocol session over an established channel.
 *
 * @internal
 */
final readonly class WorkerSession
{
    public function __construct(private float $receivePollSeconds) {}

    /**
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     */
    public function run(Channel $channel, string $workerId, string $token): int
    {
        $pid = \getmypid();
        $channel->send(new Hello($workerId, $token, $pid === false ? 1 : \max(1, $pid)));

        // Build plugins on the first assignment and reuse them for later
        // assignments. Thus, plugin construction occurs one time for each
        // worker. Run-scope harness services remain available across assignments.
        $plugins = null;
        $registry = null;
        $scopes = null;
        $executedTotal = 0;
        $artifactStore = null;

        try {
            while (true) {
                $message = $channel->receive($this->receivePollSeconds);

                if (!$message instanceof Message) {
                    if (!$channel->isEof()) {
                        // The resource scheduler can keep an idle worker without
                        // a message. No time limit applies while it waits for capacity.
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
                    static function (TestId $id, int $attempt) use ($channel): void {
                        $channel->send(new AttemptStarted($id, $attempt));
                    },
                );

                $coverage = $collector?->stop();

                if ($outcome->recycleReason instanceof RecycleReason) {
                    $scopes->closeRun();
                    $channel->send(new Recycling(
                        $outcome->recycleReason,
                        $outcome->remaining,
                        $outcome->summary,
                        $coverage,
                    ));

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
                // This is the final report attempt. No action is possible if
                // the channel is gone.
            }

            return 1;
        } finally {
            $channel->close();
        }
    }
}
