<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\TestFinished;

/**
 * Collects the slowest tests of a run for human-readable reporters.
 *
 * Reporters show only tests above the threshold. Thus, fast suites have no
 * additional output. The default footer has a limit of five lines. --profile
 * increases the limit to 25.
 *
 * @internal
 */
final class SlowTests
{
    private const float THRESHOLD_SECONDS = 0.5;

    private const int LIMIT = 5;

    private const int EXTENDED_LIMIT = 25;

    private readonly int $limit;

    /**
     * @var array<string, float>
     */
    private array $durations = [];

    public function __construct(bool $extended = false)
    {
        $this->limit = $extended ? self::EXTENDED_LIMIT : self::LIMIT;
    }

    public function record(TestFinished $event): void
    {
        if ($event->result->durationSeconds < self::THRESHOLD_SECONDS) {
            return;
        }

        $this->durations[(string) $event->result->id] = $event->result->durationSeconds;

        if (\count($this->durations) > $this->limit * 4) {
            $this->prune();
        }
    }

    /**
     * Returns the block or an empty string if no test is above the threshold.
     */
    public function render(Style $style): string
    {
        if ($this->durations === []) {
            return '';
        }

        $this->prune();
        $lines = ["\nSlowest tests:"];

        foreach ($this->durations as $id => $duration) {
            $lines[] = \sprintf('  %s %s', $style->duration($duration), $id);
        }

        return \implode("\n", $lines) . "\n";
    }

    private function prune(): void
    {
        \arsort($this->durations);
        $this->durations = \array_slice($this->durations, 0, $this->limit, preserve_keys: true);
    }
}
