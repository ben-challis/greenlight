<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * The watch mode loop: an initial run, then re-runs on debounced filesystem
 * changes. Enter forces an immediate full re-run, q quits. Each iteration is
 * an ordinary run producing ordinary reporter output; the loop only decides
 * when to run and hands the previous iteration's failed classes to the
 * runner so they execute first.
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
    ) {}

    /**
     * @param \Closure(array<string>): list<non-empty-string> $runOnce
     *        runs the suite with the given classes first and returns the
     *        classes that failed
     * @param int<1, max>|null $maxIterations loop bound for tests; null runs until q
     */
    public function run(\Closure $runOnce, ?int $maxIterations = null): void
    {
        $failedClasses = $runOnce([]);
        $iterations = 1;
        ($this->out)("\nWatching for changes. Enter re-runs everything, q quits.\n");

        while ($maxIterations === null || $iterations < $maxIterations) {
            $key = $this->keys->poll();

            if ($key === 'q') {
                return;
            }

            $runNow = $key === "\n";
            $changes = $this->detector->poll();

            if ($changes !== []) {
                ($this->out)(\sprintf("Change detected in %d file(s).\n", \count($changes)));
                $this->debouncer->noteChange($this->clock->now());
            }

            if ($runNow || $this->debouncer->shouldFire($this->clock->now())) {
                $this->debouncer->reset();
                $failedClasses = $runOnce($runNow ? [] : $failedClasses);
                ++$iterations;
                ($this->out)("\nWatching for changes. Enter re-runs everything, q quits.\n");

                continue;
            }

            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }
    }
}
