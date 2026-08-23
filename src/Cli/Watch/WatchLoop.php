<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Internal\Process\GracefulShutdown;

/**
 * Controls watch mode. It starts with one run and then runs after groups of file changes.
 *
 * run() starts the first run and then polls for changes and keys. Enter starts
 * a complete run immediately. q stops watch mode.
 *
 * Each iteration is a standard run with standard reporter output. The loop
 * only determines when to run. It gives failed classes from the previous
 * iteration to the runner. The runner executes these classes first.
 *
 * If GracefulShutdown reports a request, run() returns after the current
 * iteration. It does not wait for another change. The caller can then restore
 * the terminal and use the signal exit code.
 *
 * @internal
 */
final readonly class WatchLoop
{
    private const float POLL_INTERVAL_SECONDS = 0.1;

    /**
     * @param \Closure(string): void $out
     */
    public function __construct(
        private ChangeDetector $detector,
        private Debouncer $debouncer,
        private KeyInput $keys,
        private WatchClock $clock,
        private \Closure $out,
        private ?GracefulShutdown $shutdown = null,
        private bool $tracksStableRuns = false,
    ) {}

    /**
     * @param \Closure(list<non-empty-string>, list<FileChange>, bool, bool): WatchLoopResult $runOnce
     *        Runs one selected iteration and returns its watch state.
     * @param int<1, max>|null $maxIterations Test loop limit. A null value runs
     *        until q.
     */
    public function run(\Closure $runOnce, ?int $maxIterations = null): void
    {
        $this->detector->poll();
        $result = $runOnce([], [], true, false);
        $failedClasses = $result->failedClasses;
        $mapFresh = $result->mapPublished;
        $pending = [];

        if ($this->tracksStableRuns) {
            $changes = $this->detector->poll();
            if ($changes !== []) {
                $this->reportChanges($changes);
                $pending = $this->mergeChanges($pending, $changes);
                $this->debouncer->noteChange($this->clock->now());
                $mapFresh = false;
            }
        }

        $iterations = 1;
        ($this->out)("\nWaiting for changes. Press Enter to run all tests. Press q to quit.\n");

        while ($maxIterations === null || $iterations < $maxIterations) {
            if ($this->shutdown?->requested() === true) {
                return;
            }

            $key = $this->keys->poll();

            if ($key === 'q') {
                return;
            }

            $runNow = $key === "\n";
            $changes = $this->detector->poll();

            if ($changes !== []) {
                $this->reportChanges($changes);
                $pending = $this->mergeChanges($pending, $changes);
                $this->debouncer->noteChange($this->clock->now());
            }

            if ($runNow || $this->debouncer->shouldFire($this->clock->now())) {
                $this->debouncer->reset();
                $result = $runOnce(
                    $runNow ? [] : $failedClasses,
                    $runNow ? [] : \array_values($pending),
                    $runNow,
                    $mapFresh,
                );
                $failedClasses = $result->failedClasses;
                $mapFresh = $result->mapPublished;
                $pending = [];
                ++$iterations;

                if ($this->tracksStableRuns) {
                    $changes = $this->detector->poll();
                    if ($changes !== []) {
                        $this->reportChanges($changes);
                        $pending = $this->mergeChanges($pending, $changes);
                        $this->debouncer->noteChange($this->clock->now());
                        $mapFresh = false;
                    }
                }

                ($this->out)("\nWaiting for changes. Press Enter to run all tests. Press q to quit.\n");

                continue;
            }

            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /** @param list<FileChange> $changes */
    private function reportChanges(array $changes): void
    {
        $count = \count($changes);
        ($this->out)(\sprintf("Detected changes in %d %s.\n", $count, $count === 1 ? 'file' : 'files'));
    }

    /**
     * @param array<non-empty-string, FileChange> $pending
     * @param list<FileChange> $changes
     * @return array<non-empty-string, FileChange>
     */
    private function mergeChanges(array $pending, array $changes): array
    {
        foreach ($changes as $change) {
            $pending[$change->path] = isset($pending[$change->path])
                ? $pending[$change->path]->followedBy($change)
                : $change;
        }

        return $pending;
    }
}
