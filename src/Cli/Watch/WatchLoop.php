<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Core\GracefulShutdown;

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
    ) {}

    /**
     * @param \Closure(array<string>): list<non-empty-string> $runOnce Runs the
     *        suite with the specified classes first and returns failed classes
     * @param int<1, max>|null $maxIterations Test loop limit. A null value runs
     *        until q.
     */
    public function run(\Closure $runOnce, ?int $maxIterations = null): void
    {
        $failedClasses = $runOnce([]);
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
                $count = \count($changes);
                ($this->out)(\sprintf("Detected changes in %d %s.\n", $count, $count === 1 ? 'file' : 'files'));
                $this->debouncer->noteChange($this->clock->now());
            }

            if ($runNow || $this->debouncer->shouldFire($this->clock->now())) {
                $this->debouncer->reset();
                $failedClasses = $runOnce($runNow ? [] : $failedClasses);
                ++$iterations;
                ($this->out)("\nWaiting for changes. Press Enter to run all tests. Press q to quit.\n");

                continue;
            }

            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }
    }
}
