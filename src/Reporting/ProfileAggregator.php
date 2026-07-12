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
     * @var array<string, WorkerProfile>
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
            $this->worker($event->workerId)->spawned($event->occurredAt);

            return;
        }

        if ($event instanceof WorkerRecycled) {
            ++$this->worker($event->workerId)->recycled;

            return;
        }

        if ($event instanceof TestClassStarted && $event->workerId !== '') {
            $this->worker($event->workerId)->classStarted($event->occurredAt);

            return;
        }

        if ($event instanceof TestClassFinished && $event->workerId !== '') {
            $span = $this->worker($event->workerId)->classFinished($event->occurredAt);

            if ($span !== null) {
                $this->classDurations[$event->class] = ($this->classDurations[$event->class] ?? 0.0) + $span;
            }

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    /**
     * The rendered profile block, empty when no run completed.
     */
    public function render(Style $style): string
    {
        if (!$this->runFinished instanceof RunFinished) {
            return '';
        }

        $lines = ["\nProfile:"];
        $spawned = \count(\array_filter($this->workers, static fn(WorkerProfile $worker): bool => $worker->spawnedAt !== null));
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
            $bootLatency = $worker->bootLatency();

            if ($bootLatency !== null) {
                $bootLatencies[] = $bootLatency;
            }

            if ($worker->lastFinishAt !== null) {
                $finishTimes[$id] = $worker->lastFinishAt;
            }
        }

        if ($bootLatencies !== []) {
            $lines[] = \sprintf(
                '  Boot latency: %.3fs average (spawn to first class, %s)',
                \array_sum($bootLatencies) / \count($bootLatencies),
                Plural::count(\count($bootLatencies), 'worker'),
            );
        }

        foreach ($this->workers as $id => $worker) {
            if ($worker->classes === 0) {
                continue;
            }

            $percent = $worker->utilisationPercent();
            $utilisation = $percent === null ? '' : ', utilisation ' . $this->utilisation($style, $percent);

            $lines[] = \sprintf(
                '  Worker %s: %s, busy %.3fs%s',
                $id,
                Plural::count($worker->classes, 'class', 'classes'),
                $worker->busy,
                $utilisation,
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
                $lines[] = \sprintf('    %s %s', $style->duration($duration), $class);
            }
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * High utilisation is the healthy state, so the bands run green at 90%,
     * yellow at 70%, and red below, making idle workers stand out.
     */
    private function utilisation(Style $style, int $percent): string
    {
        $text = $percent . '%';

        if ($percent >= 90) {
            return $style->ok($text);
        }

        if ($percent >= 70) {
            return $style->warn($text);
        }

        return $style->error($text);
    }

    private function worker(string $id): WorkerProfile
    {
        return $this->workers[$id] ??= new WorkerProfile();
    }
}
