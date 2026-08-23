<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Coverage\Attribution\TestCoverageMap;
use Greenlight\Coverage\Attribution\TestCoverageMapError;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Test\TestSelection;

/**
 * Selects previous failures and tests affected by one stable file-change batch.
 *
 * @internal
 */
final readonly class ImpactedTestSelector
{
    /**
     * @param list<string> $testRoots
     * @param list<string> $sourceRoots
     * @param \Closure(): ExecutionPlan $discover
     */
    public function __construct(
        private TestSelection $completeSelection,
        private \Closure $discover,
        private string $coverageMap,
        private array $testRoots,
        private array $sourceRoots,
        private string $projectRoot,
        private string $configurationFile,
    ) {}

    /**
     * @param list<FileChange> $changes
     * @param list<non-empty-string> $failedTests
     */
    public function select(array $changes, array $failedTests, ?string $mapRunId): ImpactSelection
    {
        if ($changes === []) {
            return $this->complete('No stable file-change batch is available.');
        }

        foreach ($changes as $change) {
            if ($change->isDeleted()) {
                return $this->complete('A file was deleted or renamed.');
            }

            if ($this->samePath($change->path, $this->configurationFile)) {
                return $this->complete('The Greenlight configuration changed.');
            }

            if (!$this->inRoots($change->path, [...$this->testRoots, ...$this->sourceRoots])) {
                return $this->complete('A changed file is outside the configured test and coverage roots.');
            }
        }

        try {
            $plan = ($this->discover)();
        } catch (DiscoveryError) {
            return $this->complete('Test discovery did not produce a reliable impacted plan.');
        }

        $planIds = $this->planIds($plan);
        $selected = [];
        foreach ($failedTests as $id) {
            if (!isset($planIds[$id])) {
                return $this->complete('A previous failed test is not in the current selected plan.');
            }

            $selected[$id] = true;
        }

        /** @var array<non-empty-string, non-empty-list<positive-int>> $changedLines */
        $changedLines = [];

        foreach ($changes as $change) {
            if ($this->inRoots($change->path, $this->testRoots)) {
                $found = false;
                foreach ($plan->entries as $entry) {
                    if ($this->samePath($entry->sourceFile, $change->path)) {
                        $selected[(string) $entry->id] = true;
                        $found = true;
                    }
                }

                if (!$found) {
                    return $this->complete('A changed test file has no test in the current selected plan.');
                }

                continue;
            }

            if ($change->isAdded() || $change->hasLineCountChange()) {
                return $this->complete('A source change has no stable line mapping.');
            }

            $lines = $change->changedLines();
            if ($lines === null || $lines === []) {
                return $this->complete('A source change has no stable covered line.');
            }

            $changedLines[$change->path] = $lines;
        }

        if ($changedLines !== []) {
            if ($mapRunId === null || $mapRunId === '') {
                return $this->complete('The per-test coverage map has no current run ID.');
            }

            try {
                $impacted = TestCoverageMap::impactedTests(
                    $this->coverageMap,
                    $this->projectRoot,
                    $mapRunId,
                    $changedLines,
                );
            } catch (TestCoverageMapError) {
                return $this->complete('The per-test coverage map is missing, stale, or incomplete.');
            }

            foreach ($impacted as $id) {
                if (!isset($planIds[$id])) {
                    return $this->complete('The per-test coverage map contains a stale test ID.');
                }

                $selected[$id] = true;
            }
        }

        if ($selected === []) {
            return $this->complete('No test has a reliable impact mapping.');
        }

        $ids = [];
        foreach (\array_keys($selected) as $id) {
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return new ImpactSelection(
            $this->completeSelection->withOnlyExactIds($ids),
            false,
            \sprintf('Impacted watch selected %d %s.', \count($ids), \count($ids) === 1 ? 'test' : 'tests'),
        );
    }

    private function complete(string $reason): ImpactSelection
    {
        return new ImpactSelection(
            $this->completeSelection,
            true,
            'Impacted watch will run all selected tests. ' . $reason,
        );
    }

    /** @return array<non-empty-string, true> */
    private function planIds(ExecutionPlan $plan): array
    {
        $ids = [];
        foreach ($plan->entries as $entry) {
            $id = (string) $entry->id;
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /** @param list<string> $roots */
    private function inRoots(string $path, array $roots): bool
    {
        return \array_any($roots, fn(string $root): bool => $this->samePath($path, $root)
            || \str_starts_with($path, \rtrim($root, '/') . '/'));
    }

    private function samePath(string $left, string $right): bool
    {
        $resolvedLeft = ErrorTrap::run(static fn() => \realpath($left));
        $resolvedRight = ErrorTrap::run(static fn() => \realpath($right));

        return ($resolvedLeft === false ? $left : $resolvedLeft)
            === ($resolvedRight === false ? $right : $resolvedRight);
    }
}
