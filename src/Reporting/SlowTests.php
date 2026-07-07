<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\TestFinished;

/**
 * Collects the slowest tests of a run for the human-facing reporters. Only
 * tests over the threshold are reported, so fast suites get no extra noise,
 * and at most ten lines print.
 *
 * @internal
 */
final class SlowTests
{
    private const float THRESHOLD_SECONDS = 0.2;

    private const int LIMIT = 10;

    /**
     * @var array<string, float>
     */
    private array $durations = [];

    public function record(TestFinished $event): void
    {
        if ($event->result->durationSeconds < self::THRESHOLD_SECONDS) {
            return;
        }

        $this->durations[(string) $event->result->id] = $event->result->durationSeconds;

        if (\count($this->durations) > self::LIMIT * 4) {
            $this->prune();
        }
    }

    /**
     * The rendered block, or an empty string when nothing crossed the
     * threshold.
     */
    public function render(): string
    {
        if ($this->durations === []) {
            return '';
        }

        $this->prune();
        $lines = ["\nSlowest tests:"];

        foreach ($this->durations as $id => $duration) {
            $lines[] = \sprintf('  %.3fs %s', $duration, $id);
        }

        return \implode("\n", $lines) . "\n";
    }

    private function prune(): void
    {
        \arsort($this->durations);
        $this->durations = \array_slice($this->durations, 0, self::LIMIT, preserve_keys: true);
    }
}
