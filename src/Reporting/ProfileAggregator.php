<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;

/**
 * Derives a run profile purely from the event stream.
 *
 * Working from events alone means the same numbers come out of a live run and
 * a saved jsonl artifact.
 *
 * Timestamp semantics: class events carry worker-side clocks, worker and run
 * events carry orchestrator-side clocks. Both are wall clocks on one machine,
 * so cross clock arithmetic (boot latency) is honest to within scheduler
 * noise.
 *
 * Per worker: busy time is the sum of its class spans, the window runs from
 * its spawn (or first class, whichever is known) to its last class finish,
 * and utilisation is busy over window. Boot latency is spawn to first class
 * start, which includes the hello handshake and first assignment wait.
 *
 * @internal
 */
final class ProfileAggregator
{
    private const int SLOWEST_LIMIT = 10;

    /**
     * @var array<string, array{spawnedAt: float|null, busy: float, classes: int, openAt: float|null, firstClassAt: float|null, lastFinishAt: float|null, recycled: int}>
     */
    private array $workers = [];

    /**
     * @var array<string, float>
     */
    private array $classDurations = [];

    private ?RunStarted $runStarted = null;

    private ?RunFinished $runFinished = null;

    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            $this->runStarted = $event;

            return;
        }

        if ($event instanceof WorkerSpawned) {
            $worker = &$this->worker($event->workerId);
            $worker['spawnedAt'] ??= $event->occurredAt;

            return;
        }

        if ($event instanceof WorkerRecycled) {
            $worker = &$this->worker($event->workerId);
            ++$worker['recycled'];

            return;
        }

        if ($event instanceof TestClassStarted && $event->workerId !== '') {
            $worker = &$this->worker($event->workerId);
            $worker['openAt'] = $event->occurredAt;
            $worker['firstClassAt'] ??= $event->occurredAt;

            return;
        }

        if ($event instanceof TestClassFinished && $event->workerId !== '') {
            $worker = &$this->worker($event->workerId);

            if ($worker['openAt'] !== null) {
                $span = \max(0.0, $event->occurredAt - $worker['openAt']);
                $worker['busy'] += $span;
                $worker['openAt'] = null;
                $this->classDurations[$event->class] = ($this->classDurations[$event->class] ?? 0.0) + $span;
            }

            ++$worker['classes'];
            $worker['lastFinishAt'] = $event->occurredAt;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    /**
     * The rendered profile block, empty when no run completed.
     */
    public function render(): string
    {
        if (!$this->runFinished instanceof RunFinished) {
            return '';
        }

        $lines = ["\nProfile:"];
        $spawned = \count(\array_filter($this->workers, static fn(array $w): bool => $w['spawnedAt'] !== null));
        $recycled = \array_sum(\array_column($this->workers, 'recycled'));

        $lines[] = \sprintf(
            '  Workers: %d requested, %d spawned, %d recycled',
            $this->runStarted instanceof RunStarted ? $this->runStarted->workers : 0,
            $spawned,
            $recycled,
        );

        $bootLatencies = [];
        $finishTimes = [];

        foreach ($this->workers as $id => $worker) {
            if ($worker['spawnedAt'] !== null && $worker['firstClassAt'] !== null) {
                $bootLatencies[] = \max(0.0, $worker['firstClassAt'] - $worker['spawnedAt']);
            }

            if ($worker['lastFinishAt'] !== null) {
                $finishTimes[$id] = $worker['lastFinishAt'];
            }
        }

        if ($bootLatencies !== []) {
            $lines[] = \sprintf(
                '  Boot latency: %.3fs average (spawn to first class, %d workers)',
                \array_sum($bootLatencies) / \count($bootLatencies),
                \count($bootLatencies),
            );
        }

        foreach ($this->workers as $id => $worker) {
            if ($worker['classes'] === 0) {
                continue;
            }

            $windowStart = $worker['spawnedAt'] ?? $worker['firstClassAt'];
            $windowEnd = $worker['lastFinishAt'];
            $window = $windowStart !== null && $windowEnd !== null ? \max(0.0, $windowEnd - $windowStart) : 0.0;

            $lines[] = \sprintf(
                '  Worker %s: %d classes, busy %.3fs%s',
                $id,
                $worker['classes'],
                $worker['busy'],
                $window > 0.0 ? \sprintf(', utilisation %d%%', (int) \round(100 * \min(1.0, $worker['busy'] / $window))) : '',
            );
        }

        if (\count($finishTimes) > 1) {
            $lines[] = \sprintf(
                '  Makespan spread: %.3fs between first and last worker finish',
                \max($finishTimes) - \min($finishTimes),
            );
        }

        if ($this->classDurations !== []) {
            \arsort($this->classDurations);
            $lines[] = '  Slowest classes:';

            foreach (\array_slice($this->classDurations, 0, self::SLOWEST_LIMIT, preserve_keys: true) as $class => $duration) {
                $lines[] = \sprintf('    %.3fs %s', $duration, $class);
            }
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @return array{spawnedAt: float|null, busy: float, classes: int, openAt: float|null, firstClassAt: float|null, lastFinishAt: float|null, recycled: int}
     */
    private function &worker(string $id): array
    {
        $this->workers[$id] ??= [
            'spawnedAt' => null,
            'busy' => 0.0,
            'classes' => 0,
            'openAt' => null,
            'firstClassAt' => null,
            'lastFinishAt' => null,
            'recycled' => 0,
        ];

        return $this->workers[$id];
    }
}
