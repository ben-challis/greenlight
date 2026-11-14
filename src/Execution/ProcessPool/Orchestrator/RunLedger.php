<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\CoverageMapBuilder;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestId;

/**
 * Records the results of one orchestrated run.
 *
 * The ledger keeps the result summary and the plan entries of tests without
 * a result. It also collects coverage, leaks, and warnings from workers.
 * failureLimitReached() and remainingFailureAllowance() apply the
 * failed-or-errored test limit to the summary.
 *
 * @internal
 */
final class RunLedger
{
    private ResultSummary $summary;

    /**
     * @var array<string, PlanEntry>
     */
    private array $entriesById = [];

    private ?CoverageMapBuilder $coverage = null;

    /**
     * @var list<TestId>
     */
    private array $leaks = [];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(private readonly ?int $stopAfterFailures = null)
    {
        $this->summary = new ResultSummary();
    }

    public function index(ExecutionPlan $plan): void
    {
        foreach ($plan->entries as $entry) {
            $this->entriesById[(string) $entry->id] = $entry;
        }
    }

    /**
     * Returns the plan entry of a test without a result.
     */
    public function entry(TestId $id): ?PlanEntry
    {
        return $this->entriesById[(string) $id] ?? null;
    }

    /**
     * Adds a test result to the summary.
     *
     * Tests with a result do not need plan lookups. Thus, the index contains
     * only tests without a result and becomes smaller during the run.
     */
    public function recordResult(TestId $id, Outcome $outcome): void
    {
        unset($this->entriesById[(string) $id]);
        $this->summary = $this->summary->add($outcome);
    }

    public function summary(): ResultSummary
    {
        return $this->summary;
    }

    public function failureLimitReached(): bool
    {
        return $this->stopAfterFailures !== null
            && $this->summary->failed + $this->summary->errored >= $this->stopAfterFailures;
    }

    /**
     * @return positive-int|null
     */
    public function remainingFailureAllowance(): ?int
    {
        if ($this->stopAfterFailures === null) {
            return null;
        }

        return \max(1, $this->stopAfterFailures - $this->summary->failed - $this->summary->errored);
    }

    public function mergeCoverage(?CoverageMap $coverage): void
    {
        if (!$coverage instanceof CoverageMap) {
            return;
        }

        $this->coverage ??= new CoverageMapBuilder();
        $this->coverage->add($coverage);
    }

    /**
     * Returns null when no worker reported coverage.
     */
    public function coverage(): ?CoverageMap
    {
        return $this->coverage?->build();
    }

    /**
     * @param list<TestId> $leaks
     */
    public function addLeaks(array $leaks): void
    {
        $this->leaks = [...$this->leaks, ...$leaks];
    }

    /**
     * @return list<TestId>
     */
    public function leaks(): array
    {
        return $this->leaks;
    }

    public function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
