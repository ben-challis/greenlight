<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Config\Configuration;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Runner\Worker\Worker;

/**
 * Discovery plus one in-process worker plus a summary. This is the whole
 * runner until the process pool exists; after that it remains the workers=1
 * fallback for hosts without process support.
 *
 * @internal
 */
final readonly class InProcessRunner
{
    /**
     * @param list<non-empty-string> $directories
     *
     * @throws DiscoveryError
     */
    public function run(
        Configuration $configuration,
        array $directories,
        EventSink $sink,
        ?CoverageSettings $coverageSettings = null,
    ): RunResult {
        $seed = null;

        if ($configuration->randomizeOrder) {
            $seed = $configuration->randomSeed ?? \random_int(0, 2 ** 31 - 1);
        }

        $plan = $this->discover($configuration, $directories, $seed);

        $runId = \bin2hex(\random_bytes(8));
        $startedAt = \hrtime(true);

        $sink->emit(new RunStarted($runId, \count($plan), 1, \microtime(true)));

        $collector = $coverageSettings instanceof CoverageSettings ? CoverageCollector::create($coverageSettings) : null;
        $collector?->start();

        $summary = new Worker(DefaultServices::registry())
            ->run($plan, $sink, $configuration->stopAfterFailures)
            ->summary;

        $coverage = $collector?->stop();

        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $sink->emit(new RunFinished($runId, $summary, $durationSeconds, \microtime(true)));

        return new RunResult($summary, \count($plan), $durationSeconds, $seed, $coverage);
    }

    /**
     * @param list<non-empty-string> $directories
     */
    private function discover(Configuration $configuration, array $directories, ?int $seed): ExecutionPlan
    {
        $filter = new Filter(includeGroups: $configuration->groups);

        return new TestDiscoverer()->discover($directories, $filter, $seed);
    }
}
