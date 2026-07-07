<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Config\Configuration;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Runner\Worker\EventSink;

/**
 * Discovery plus the process pool. The default runner; workers=1 falls back
 * to the in-process runner at the call site.
 *
 * @internal
 */
final readonly class ParallelRunner
{
    /**
     * @param non-empty-list<non-empty-string> $workerCommand argv prefix invoking bin/greenlight
     */
    public function __construct(
        private array $workerCommand,
        private string $workingDirectory,
    ) {}

    /**
     * @param list<non-empty-string> $directories
     * @param positive-int $workerCount
     *
     * @throws DiscoveryError
     */
    public function run(
        Configuration $configuration,
        array $directories,
        EventSink $sink,
        int $workerCount,
        ?CoverageSettings $coverageSettings = null,
    ): RunResult {
        $seed = null;

        if ($configuration->randomizeOrder) {
            $seed = $configuration->randomSeed ?? \random_int(0, 2 ** 31 - 1);
        }

        $filter = new Filter(includeGroups: $configuration->groups);
        $plan = new TestDiscoverer()->discover($directories, $filter, $seed);

        $runId = \bin2hex(\random_bytes(8));
        $startedAt = \hrtime(true);

        $sink->emit(new RunStarted($runId, \count($plan), $workerCount, \microtime(true)));

        $orchestrator = new Orchestrator(
            $this->workerCommand,
            $this->workingDirectory,
            $configuration->recycleAfterTests,
            $configuration->recycleAboveMemoryBytes,
            $configuration->stopAfterFailures,
            $coverageSettings,
        );

        $summary = $orchestrator->run($plan, $sink, $workerCount);

        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $sink->emit(new RunFinished($runId, $summary, $durationSeconds, \microtime(true)));

        return new RunResult($summary, \count($plan), $durationSeconds, $seed, $orchestrator->collectedCoverage());
    }
}
