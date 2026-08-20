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
 * Makes a run profile only from the event stream.
 *
 * Thus, a live run and a saved JSONL artifact produce the same values.
 *
 * Class events use worker clocks. Worker and run events use orchestrator
 * clocks. These clocks use host wall time. Thus, boot latency can
 * include a scheduler delay.
 *
 * Worker busy time is the sum of its test-class periods. Utilization is busy
 * time divided by the worker period for a non-isolated worker. Boot latency is
 * the time from process start to the first test class. It includes the hello
 * exchange and the wait for the first assignment.
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
            $this->worker($event->workerId)->classStarted($event->occurredAt, $event->isolated);

            return;
        }

        if ($event instanceof TestClassFinished && $event->workerId !== '') {
            $span = $this->worker($event->workerId)->classFinished($event->occurredAt);

            if ($span !== null) {
                $this->classDurations[$event->class] = ProfileDuration::add(
                    $this->classDurations[$event->class] ?? 0.0,
                    $span,
                );
            }

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    /**
     * Returns the profile block or an empty string if no run completed.
     */
    public function render(Style $style): string
    {
        if (!$this->runFinished instanceof RunFinished) {
            return '';
        }

        $lines = ["\nProfile:"];
        $spawned = \count(\array_filter($this->workers, static fn(WorkerProfile $worker): bool => $worker->spawnedAt !== null));
        $isolated = \count(\array_filter($this->workers, static fn(WorkerProfile $worker): bool => $worker->isolated));
        $recycled = \array_sum(\array_column($this->workers, 'recycled'));

        $workerSummary = \sprintf(
            '  Workers: %d requested, %d spawned',
            $this->runStarted instanceof RunStarted ? $this->runStarted->workers : 0,
            $spawned,
        );

        if ($isolated > 0) {
            $workerSummary .= \sprintf(', %d isolated', $isolated);
        }

        $lines[] = $workerSummary . \sprintf(', %d recycled', $recycled);

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
                ProfileDuration::average($bootLatencies),
                Plural::count(\count($bootLatencies), 'worker'),
            );
        }

        $rows = [];

        foreach ($this->workers as $id => $worker) {
            if ($worker->classes === 0) {
                continue;
            }

            $rows[] = [
                $id,
                (string) $worker->classes,
                \sprintf('%.3fs', $worker->busy),
                $worker->isolated ? null : $worker->utilizationPercent(),
                $worker->isolated,
            ];
        }

        if ($rows !== []) {
            $lines[] = '';

            foreach ($this->workerTable($style, $rows, $isolated > 0) as $line) {
                $lines[] = $line;
            }
        }

        if (\count($finishTimes) > 1) {
            $lines[] = \sprintf(
                '  Makespan spread: %.3fs between first and last worker finish',
                ProfileDuration::between(\min($finishTimes), \max($finishTimes)),
            );
        }

        if ($this->classDurations !== []) {
            \arsort($this->classDurations);
            $lines[] = '';
            $lines[] = '  Slowest classes:';
            $slowest = \array_slice($this->classDurations, 0, self::SLOWEST_LIMIT, preserve_keys: true);
            // 6 is the floor a %.3fs render can occupy (0.000s).
            $width = \max(6, ...\array_map(static fn(float $duration): int => \strlen(\sprintf('%.3fs', $duration)), \array_values($slowest)));

            foreach ($slowest as $class => $duration) {
                // Add space outside the color codes. Thus, escape sequences
                // cannot change the alignment.
                $pad = \str_repeat(' ', $width - \strlen(\sprintf('%.3fs', $duration)));
                $lines[] = \sprintf('    %s%s  %s', $pad, $style->duration($duration), $class);
            }
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * Returns the worker statistics table.
     *
     * Worker IDs align left, and numeric columns align right. The data determines
     * the column widths.
     *
     * @param list<array{string, string, string, ?int, bool}> $rows
     *
     * @return list<string>
     */
    private function workerTable(Style $style, array $rows, bool $showIsolation): array
    {
        $workerWidth = \max(\strlen('Worker'), ...\array_map(static fn(array $row): int => \strlen($row[0]), $rows));
        $classesWidth = \max(\strlen('Classes'), ...\array_map(static fn(array $row): int => \strlen($row[1]), $rows));
        $busyWidth = \max(\strlen('Busy'), ...\array_map(static fn(array $row): int => \strlen($row[2]), $rows));
        $utilWidth = \max(\strlen('Util'), ...\array_map(static fn(array $row): int => \strlen($row[3] . '%'), $rows));

        $lines = [\rtrim(\sprintf(
            '  %s  %s  %s  %s%s',
            \str_pad('Worker', $workerWidth),
            \str_pad('Classes', $classesWidth, ' ', \STR_PAD_LEFT),
            \str_pad('Busy', $busyWidth, ' ', \STR_PAD_LEFT),
            \str_pad('Util', $utilWidth, ' ', \STR_PAD_LEFT),
            $showIsolation ? '  Isolated' : '',
        ))];

        foreach ($rows as [$id, $classes, $busy, $percent, $isolated]) {
            // Add space outside the color codes. Thus, escape sequences cannot
            // change the alignment.
            $util = $percent === null
                ? \str_repeat(' ', $utilWidth)
                : \str_repeat(' ', $utilWidth - \strlen($percent . '%')) . $this->utilization($style, $percent);

            $lines[] = \rtrim(\sprintf(
                '  %s  %s  %s  %s%s',
                \str_pad($id, $workerWidth),
                \str_pad($classes, $classesWidth, ' ', \STR_PAD_LEFT),
                \str_pad($busy, $busyWidth, ' ', \STR_PAD_LEFT),
                $util,
                $showIsolation ? '  ' . ($isolated ? 'yes' : '') : '',
            ));
        }

        return $lines;
    }

    /**
     * Uses green for utilization of 90 percent or more. Uses yellow from 70
     * percent. Uses red below 70 percent. Thus, idle workers are easy to see.
     */
    private function utilization(Style $style, int $percent): string
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
